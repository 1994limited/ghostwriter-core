<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Violation;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\Northfold;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange\RequiredFieldsTest;
use Symfony\Component\Yaml\Yaml;

/**
 * The Northfold Pages bug, through FakeProvider: the hero's image (and
 * link, entries and settings) are required, and the planner's layouts each
 * make their own hero. They are offered, and those that fail say why.
 */
final class RequiredFieldsPlannerTest extends StudioTestCase
{
    private function site(): LayoutContext
    {
        return new LayoutContext(Northfold::pages(), Pattern::fromArray(['blocks' => ['page_builder' => ['fixed' => ['hero' => ['style' => 'light']]]]]));
    }

    /**
     * @return array{0: Session, 1: SessionLayouts}
     */
    private function firstDraft(string $plans): array
    {
        $draft = "<reply>Here is a first draft.</reply>\n<draft>\n".Yaml::dump(Northfold::pagesDraft(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK).'</draft>';
        $this->fake->respond('writer', self::reply($draft));
        $this->fake->respond('layout-planner', self::reply("<plans>\n{$plans}\n</plans>"));

        $session = Session::start(Format::Craft, 'page', ['brief' => 'Winter care.']);
        $conversation = new Conversation([['role' => 'user', 'content' => 'Winter care.']]);
        $context = new WriterContext(new ContentKind('page', 'Page'), '', Layout::fromSchema(Northfold::pages()), '');
        $studio = $this->studio();
        $layouts = new SessionLayouts($studio, new Layouts(new LayoutOptions(linkSentinels: true), new HtmlDialect, new CraftLinks(link: ['craft\\fields\\Link'])), $this->logger());

        $response = $studio->write($conversation, $context);
        $session->answer($response->reply, $response->document);
        $layouts->afterWriter($session, null, $response, $conversation, $context, $this->site());

        return [$session, $layouts];
    }

    public function test_the_planners_layouts_survive_a_required_hero_image(): void
    {
        [$session, $layouts] = $this->firstDraft(RequiredFieldsTest::ALTERNATIVES);

        $this->assertSame(['writer', 'layout-planner'], array_map(fn ($request) => $request->agent, $this->fake->requests()));
        $this->assertSame(['w', 'p1', 'p2'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()));
        $this->assertSame([], $layouts->planned()?->dropped);
        $this->assertStringContainsString('- hero: heading (text, one line, required), subheading (text, one line), image (image), style (choice), wide (toggle)', $this->sent('layout-planner')->prompt, 'the planner is told only what it must write in is required');

        foreach (['p1', 'p2'] as $id) {
            $built = $layouts->build($session, $this->site(), $id);
            $hero = $built->data['page_builder'][0];

            $this->assertSame(['hero', 'Winter garden care', 'light'], [$hero['type'], $hero['heading'], $hero['style']], $id);
            $this->assertArrayNotHasKey('image', $hero, "{$id}: left for the placeholder, as in the draft");
        }
    }

    public function test_a_dropped_layout_records_the_rule_that_dropped_it(): void
    {
        $sections = explode("\n- name: Early call", RequiredFieldsTest::ALTERNATIVES)[0];
        [$session, $layouts] = $this->firstDraft($sections."\n".RequiredFieldsTest::NO_HEADING);

        $this->assertSame(['w', 'p1'], array_map(fn (Plan $plan) => $plan->id, $layouts->plans($session)->all()));
        $this->assertSame(['p2' => [Violation::REQUIRED]], $layouts->planned()?->rules());
        $this->assertSame('hero: heading is required.', $layouts->planned()?->dropped['p2'][0]->message);
        $this->assertStringContainsString('layout \"No heading\" was dropped (required)', $this->logged());
        $this->assertStringContainsString('2 layouts and 1 were dropped (p2: required)', $this->logged());
    }
}
