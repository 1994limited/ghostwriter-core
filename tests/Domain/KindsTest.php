<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use PHPUnit\Framework\TestCase;

final class KindsTest extends TestCase
{
    public function test_every_group_has_a_general_brief_in_its_own_words(): void
    {
        $statamic = ContentType::generic(Format::Statamic, 'journal', 'Journal');
        $this->assertSame('any:journal', $statamic->handle);
        $this->assertTrue($statamic->isGeneric());
        $this->assertSame('A general brief for anything in Journal. Pick entries to model it on, or describe what you want and let Ghostwriter choose the shape.', $statamic->description);
        $this->assertStringStartsWith('There is no set recipe for this entry.', $statamic->guidance);
        $this->assertStringEndsWith('preferring those this collection already uses.', $statamic->guidance);

        // Word for word as the addons' ContentType::generic() had it.
        $this->assertSame("There is no set recipe for this entry.\n\nIf example entries are shown, they were chosen as the model: follow their structure block for block and match their length. Keep unchanged any block that is identical across the examples, such as process steps or testimonials, and never reword a quotation.\n\nIf the brief asks for a different shape, the brief wins. With no examples to follow, choose the fields and blocks that suit what the brief asks for, preferring those this collection already uses.", $statamic->guidance);
        $this->assertSame(['handle' => 'shape', 'label' => 'Anything about its shape or length?', 'instructions' => 'Leave blank to follow the entries it is modelled on.', 'type' => 'text'], $statamic->questions[3]);
        $this->assertSame(['Every fact comes from the brief or the conversation.', 'Its structure follows the chosen examples, or suits the brief where there are none.'], $statamic->checklist);

        $craft = ContentType::generic(Format::Craft, 'news', 'News');
        $this->assertStringEndsWith('preferring those this section already uses.', $craft->guidance);

        $filament = ContentType::generic(Format::Filament, 'posts', 'Posts');
        $this->assertSame('A general brief for anything in Posts. Pick records to model it on, or describe what you want and let Ghostwriter choose the shape.', $filament->description);
        $this->assertStringStartsWith("There is no set recipe for this.\n\nIf example records are shown", $filament->guidance);
        $this->assertSame('The subject, and what it is for.', $filament->questions[0]['instructions']);
        $this->assertSame("There is no set recipe for this.\n\nIf example records are shown, they were chosen as the model: follow their structure block for block and match their length. Keep unchanged any block that is identical across the examples, such as process steps or testimonials, and never reword a quotation.\n\nIf the brief asks for a different shape, the brief wins. With no examples to follow, choose the fields and blocks that suit what the brief asks for, preferring those this resource already uses.", $filament->guidance);
        $this->assertSame('Leave blank to follow the records it is modelled on.', $filament->questions[3]['instructions']);
        $this->assertSame(['subject', 'reader', 'points', 'shape', 'must_not_appear'], array_column($filament->questions, 'handle'));
    }

    public function test_a_kind_is_modelled_on_a_pieces_own_examples_and_variant(): void
    {
        $type = new ContentType(Format::Statamic, 'guide', 'Guide', '', 'journal', [], '', where: ['category' => 'guides'], examples: ['a']);
        $this->assertSame($type, $type->modelledOn([]));

        $modelled = $type->modelledOn(['b', 'c']);
        $this->assertSame(['b', 'c'], $modelled->examples);
        $this->assertSame([], $modelled->where, 'Chosen examples replace the filter.');

        $session = Session::start(Format::Statamic, 'guide', [], 'u', ['d']);
        $session->variant = 'journal_long';
        $forSession = $type->forSession($session);
        $this->assertSame(['d'], $forSession->examples);
        $this->assertSame('journal_long', $forSession->variant);
    }

    public function test_missing_answers_are_named(): void
    {
        $type = new ContentType(Format::Craft, 'guide', 'Guide', '', 'news', [
            ['handle' => 'subject', 'label' => 'Subject', 'required' => true],
            ['handle' => 'notes', 'label' => 'Notes'],
        ], '');

        $this->assertSame(['subject' => 'This needs an answer.'], $type->missing(['subject' => '   ']));
        $this->assertSame(['notes' => 'Keep this under 20,000 characters.'], $type->missing(['subject' => 'x', 'notes' => str_repeat('a', 20001)]));
        $this->assertSame([], $type->missing(['subject' => 'x']));
    }

    public function test_a_new_handle_is_made_from_the_title_in_each_addons_way(): void
    {
        $this->assertSame('whats-new-2', ContentType::handleFor(Format::Statamic, 'What’s new', 'journal', ['whats-new']));
        $this->assertSame('journal', ContentType::handleFor(Format::Statamic, '!!!', 'journal', []));
        $this->assertSame('what-s-new', ContentType::handleFor(Format::Craft, 'What’s new', 'news', []));
        $this->assertSame('cafe-guide', ContentType::handleFor(Format::Filament, 'Café guide', 'posts', []));
        $this->assertSame('posts', ContentType::handleFor(Format::Filament, '', 'posts', []));
        $this->assertSame('kind', ContentType::handleFor(Format::Filament, '', '', []));
    }

    public function test_the_studio_gets_the_same_kind(): void
    {
        $kind = ContentType::generic(Format::Craft, 'news', 'News')->toStudio();

        $this->assertSame('any:news', $kind->handle);
        $this->assertSame('Something new', $kind->title);
        $this->assertCount(5, $kind->questions);
    }

    public function test_suggestions_are_stored_with_ids_and_turned_down_by_title(): void
    {
        $state = KindSuggestions::empty(Format::Craft);
        $state->begin();
        $state->store([['title' => 'Site page'], ['title' => 'Case study']], 12, new DateTimeImmutable('2026-10-02T12:00:00+00:00'));

        $this->assertSame('idle', $state->status);
        $this->assertSame('2026-10-02T12:00:00+00:00', $state->checkedAt);
        $this->assertSame(12, $state->records);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $state->suggestions[0]['id']);

        $state->remove($state->suggestions[0]['id'], dismissed: true);
        $state->remove($state->suggestions[0]['id']);

        $this->assertSame([], $state->suggestions);
        $this->assertSame(['Site page'], $state->dismissed, 'Learned ones are not remembered as dismissed.');
        $this->assertSame(['status' => 'idle', 'error' => null, 'checkedAt' => '2026-10-02T12:00:00+00:00', 'entries' => 12, 'suggestions' => [], 'dismissed' => ['Site page']], $state->toArray());
    }

    public function test_a_group_is_due_a_look_when_new_or_ten_more_are_published(): void
    {
        $state = KindSuggestions::empty(Format::Statamic);

        $this->assertFalse($state->due(1), 'Two published at least.');
        $this->assertTrue($state->due(2));

        $state->store([], 5);
        $this->assertFalse($state->due(14));
        $this->assertTrue($state->due(15));

        $state->fail('x');
        $this->assertFalse($state->due(50), 'Not while a failure shows.');
    }

    public function test_filament_keeps_its_learning_queue(): void
    {
        $state = KindSuggestions::empty(Format::Filament);

        $this->assertSame(['status' => 'idle', 'error' => null, 'checked_at' => null, 'records' => 0, 'suggestions' => [], 'dismissed' => [], 'learning' => ['status' => 'idle', 'error' => null, 'queue' => []]], $state->toArray());
    }
}
