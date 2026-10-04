<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\PlanSchemaTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * Through FakeProvider: the writer proposes "5 areas" for a list of three;
 * core counts 3, the planner places it, and the page can't go live until
 * the editor checks it.
 */
final class DerivedCountTest extends StudioTestCase
{
    private const BRIEF = "Winter care: four visits between November and February.\n\n**Areas**\nNorthumberland, Durham and the Tyne Valley";

    private const PLANS = <<<'YAML'
        <plans>
        - name: With the numbers
          description: The areas up front
          page_builder:
            - type: hero
              place: { heading: u3, subheading: u4, image: u5 }
            - type: stats
              place: { items: [x1.1] }
            - type: text
              place: { body: [u6, u7, u8] }
            - type: cta
              place: { heading: u9, button: u10 }
        </plans>
        YAML;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function formats(): iterable
    {
        yield 'tagged YAML' => [false];
        yield 'structured output' => [true];
    }

    #[DataProvider('formats')]
    public function test_the_model_proposes_five_areas_and_core_counts_three_for_the_editor_to_check(bool $structured): void
    {
        $this->fake->withoutStructuredOutput(! $structured);
        $extras = "<extras>\n- kind: stats\n  items:\n    - text: \"5 areas\"\n      value: \"5\"\n      label: areas\n      source: { from: brief, quote: \"Northumberland, Durham and the Tyne Valley\" }\n</extras>";
        $this->fake->respond('writer', self::reply("<reply>Here is a first draft.</reply>\n<draft>\n".Yaml::dump(Northfold::blocksDraft(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)."</draft>\n".$extras));
        $b = PlanSchemaTest::block(...);
        $structured
            ? $this->fake->respondStructured('layout-planner', ['plans' => [['notes' => 'The areas up front.', 'name' => 'With the numbers', 'description' => 'The areas up front', 'follows' => '', 'fields' => [[
                'field' => 'page_builder',
                'blocks' => [
                    $b('hero', [['heading', ['u3']], ['subheading', ['u4']], ['image', ['u5']]]),
                    $b('stats', [['items', ['x1.1']]]),
                    $b('text', [['body', ['u6', 'u7', 'u8']]]),
                    $b('cta', [['heading', ['u9']], ['button', ['u10']]]),
                ],
                'constructs' => [],
                'refs' => [],
            ]]]]])
            : $this->fake->respond('layout-planner', self::reply(self::PLANS));

        $session = Session::start(Format::Statamic, 'service', ['brief' => self::BRIEF]);
        $session->messages = [['role' => 'user', 'content' => self::BRIEF]];
        $conversation = new Conversation($session->messages);
        $context = new WriterContext(new ContentKind('service', 'Service'), '', Layout::fromSchema(Northfold::blocks()), '');
        $studio = $this->studio();
        $layouts = new SessionLayouts($studio, new Layouts, $this->logger());
        $site = new LayoutContext(Northfold::blocks(), null, []);

        $response = $studio->write($conversation, $context);
        $session->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
        $layouts->afterWriter($session, null, $response, $conversation, $context, $site);

        $this->assertStringContainsString('Ghostwriter counts the list itself', $this->sent('writer')->instructions, 'the writer is told core counts');

        // Core's count, not the model's, with the list it came from.
        $item = $layouts->extras($session)->item('x1.1');
        $marker = '[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]';
        $this->assertSame($marker, $item?->text);
        $this->assertSame('[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', $item->parts['value'] ?? null);
        $this->assertSame([3, true, 'Counted from your brief: “Northumberland, Durham and the Tyne Valley”', 'Needs review'], [$item->count?->count(), $item->needsReview(), $item->countLabel()?->english(), $item->state()?->english()]);
        $this->assertStringContainsString('stats item 1: counted 3 in its quote (it said 5)', json_encode(array_column($this->logs, 'context')) ?: '');
        $this->assertStringContainsString('x1.1 [stats] "'.$marker.'"', $this->sent('layout-planner')->prompt, 'the planner sees the marker, as it is placed');

        // The planner's layout places it, marker and all.
        $layouts->choose($session, 'p1');
        $built = $layouts->build($session, $site);
        $this->assertSame([], $built->notes);
        $stats = array_values(array_filter($built->data['page_builder'], fn (array $block) => $block['type'] === 'stats'));
        $this->assertSame([['value' => '[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', 'label' => 'areas']], $stats[0]['items']);

        // Finish this page: a step, and publishing blocked until it's checked.
        $gaps = new GapContext(schema: Northfold::blocks(), entry: new EntryData($built->data), sources: ExtraSources::fromSession($session)->all());
        $check = GapFinder::standard()->find($gaps)->ofKind(GapKind::Check);
        $this->assertCount(1, $check);
        $this->assertNull($check[0]->meta['stale']);
        $this->assertSame('I counted 3 from “Northumberland, Durham and the Tyne Valley”. Is that right?', $check[0]->message()->english());
        $this->assertSame([FixAction::Confirm, FixAction::Change, FixAction::Remove], array_map(fn ($fix) => $fix->action, $check[0]->fixes));
        $this->assertTrue(PublishReadiness::standard()->check($gaps)->blocked());
        $this->assertContains($check[0]->id, array_map(fn ($gap) => $gap->id, PublishReadiness::standard()->check($gaps)->problems()));

        // "Looks right".
        $data = $built->data;
        $data['page_builder'][1]['items'][0]['value'] = Markers::resolveCheck($data['page_builder'][1]['items'][0]['value'], (string) $check[0]->meta['match'], (string) $check[0]->fixes[0]->value);
        $this->assertSame('3', $data['page_builder'][1]['items'][0]['value']);
        $this->assertSame([], GapFinder::standard()->find(new GapContext(schema: Northfold::blocks(), entry: new EntryData($data)))->ofKind(GapKind::Check));
    }
}
