<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use PHPUnit\Framework\TestCase;

/** A count Ghostwriter made from a list: marked, found, confirmed, and told when its list has changed. */
final class CheckGapsTest extends TestCase
{
    private const MARKER = '[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]';

    private const BRIEF = "Winter care.\n\n**Areas**\nNorthumberland, Durham and the Tyne Valley";

    public function test_the_marker_is_written_strictly_and_found_leniently(): void
    {
        $this->assertSame(self::MARKER, Markers::check('3 areas', 'Northumberland, Durham and the Tyne Valley'));
        $this->assertSame('[[check: 2 places | from: A (north); B / C]]', Markers::check("2\nplaces", 'A [north]; B | C'));

        $text = 'We visit gardens in '.self::MARKER.'. And [[ CHECK:2 visits|FROM: Mondays and Fridays, or never ]] too.';
        $checks = Markers::checks($text);

        $this->assertSame(['3 areas', '2 visits'], array_column($checks, 'value'));
        $this->assertSame(['Northumberland, Durham and the Tyne Valley', 'Mondays and Fridays, or never'], array_column($checks, 'list'));
        $this->assertSame(self::MARKER, $checks[0]['match']);
        $this->assertTrue(Markers::has('In '.self::MARKER));
        $this->assertStringContainsString('[[check: 2 visits | from: Mondays and Fridays, or never]]', Markers::normalise($text));
        $this->assertSame('We visit gardens in 3 areas. And 2 visits too.', Markers::withoutChecks($text));
        $this->assertSame([], Markers::placeholderText('In '.self::MARKER), 'a count to check is not text waiting for something');
        $this->assertSame(['[check the date]'], array_column(Markers::placeholderText('Open [check the date].'), 'match'));
    }

    public function test_resolving_a_marker_replaces_only_that_one(): void
    {
        $text = 'In '.self::MARKER.', and again '.self::MARKER.'.';

        $this->assertSame('In 3 areas, and again '.self::MARKER.'.', Markers::resolveCheck($text, self::MARKER, '3 areas'));
        $this->assertSame('In '.self::MARKER.', and again four areas.', Markers::resolveCheck($text, self::MARKER, 'four areas', 1));
        $this->assertSame('Gardens in.', Markers::resolveCheck('Gardens in '.self::MARKER.'.', self::MARKER, ''));
        $this->assertSame('Gardens across the North.', Markers::resolveCheck('Gardens in '.self::MARKER.' across the North.', 'Gardens in '.self::MARKER, 'Gardens'), 'any text the editor confirms');
        $this->assertSame('Nothing here.', Markers::resolveCheck('Nothing here.', self::MARKER, '3 areas'));
    }

    public function test_the_front_end_finds_the_same_counts(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            $this->markTestSkipped('Node is not installed.');
        }

        $script = <<<'JS'
            const p = require(process.argv[1]);
            const text = process.argv[2];
            const all = (r, g) => [...text.matchAll(new RegExp(r.source, r.flags))].map(m => m[g]);
            console.log(JSON.stringify([all(p.check, 1), all(p.check, 2), Object.values(p.placeholderText).flatMap(r => all(r, 0))]));
            JS;
        $text = 'In '.self::MARKER.' and [[ check:2 visits|from: Mondays and Fridays, or never ]] [check the date]';

        $out = shell_exec(escapeshellarg($node).' -e '.escapeshellarg($script).' '.escapeshellarg(Markers::patternsFile()).' '.escapeshellarg($text));

        $this->assertSame([
            array_column(Markers::checks($text), 'value'),
            array_column(Markers::checks($text), 'list'),
            ['[check the date]'],
        ], json_decode((string) $out, true));
    }

    public function test_a_count_to_check_is_a_gap_that_asks_the_editor(): void
    {
        $report = GapFinder::standard()->find($this->context('Gardens in '.self::MARKER.'.', [self::BRIEF]));
        $gaps = $report->ofKind(GapKind::Check);

        $this->assertCount(1, $gaps);
        $gap = $gaps[0];
        $this->assertSame('check|blocks/#s1/value|3 areas|0', $gap->id);
        $this->assertSame(Severity::Blocks, $gap->severity);
        $this->assertSame(['3 areas', 3, 'Northumberland, Durham and the Tyne Valley', ['Northumberland', 'Durham', 'the Tyne Valley'], null], [$gap->meta['value'], $gap->meta['count'], $gap->meta['list'], $gap->meta['items'], $gap->meta['stale']]);
        $this->assertSame('I counted 3 areas from “Northumberland, Durham and the Tyne Valley”. Is that right?', $gap->message()->english());
        $this->assertSame('Check me', (new Message($gap->kind->speech()))->english());
        $this->assertSame(
            [['confirm', 'Looks right', '3 areas', true], ['change', 'Change it', '3 areas', false], ['remove', 'Remove it', null, false]],
            array_map(fn (Fix $fix) => [$fix->action->value, $fix->label->english(), $fix->value, $fix->primary], $gap->fixes),
        );
        $this->assertSame([], $report->ofKind(GapKind::PlaceholderText));

        $this->assertSame([], GapFinder::standard()->find($this->context(Markers::resolveCheck('Gardens in '.self::MARKER.'.', self::MARKER, '3 areas')))->ofKind(GapKind::Check), 'Looks right leaves plain text');
    }

    public function test_a_list_edited_since_it_was_counted_says_so_and_offers_the_new_count(): void
    {
        $changed = GapFinder::standard()->find($this->context('Gardens in '.self::MARKER.'.', ["Winter care.\n\nAreas: Northumberland, Durham, Cumbria and the Tyne Valley"]))->ofKind(GapKind::Check)[0];

        $this->assertSame(['changed', 4, '4 areas', 'Northumberland, Durham, Cumbria and the Tyne Valley'], [$changed->meta['stale'], $changed->meta['newCount'], $changed->meta['newValue'], $changed->meta['newList']]);
        $this->assertSame('I counted 3 areas from “Northumberland, Durham and the Tyne Valley”, but that list has changed since. It now has 4. Use “4 areas” instead?', $changed->message()->english());
        $this->assertSame([[FixAction::Confirm, 'Use “4 areas”', '4 areas', true], [FixAction::Change, 'Change it', '3 areas', false], [FixAction::Remove, 'Remove it', null, false]], array_map(fn (Fix $fix) => [$fix->action, $fix->label->english(), $fix->value, $fix->primary], $changed->fixes));
        $this->assertSame($this->gapId(), $changed->id, 'the same gap, so the guide keeps its place');

        $gone = GapFinder::standard()->find($this->context('Gardens in '.self::MARKER.'.', ['Winter care, across the North East.']))->ofKind(GapKind::Check)[0];
        $this->assertSame('gone', $gone->meta['stale']);
        $this->assertArrayNotHasKey('newCount', $gone->meta);
        $this->assertSame('I counted 3 areas from “Northumberland, Durham and the Tyne Valley”, but that list isn\'t in what you gave me any more. Is 3 areas still right?', $gone->message()->english());
        $this->assertSame([[FixAction::Change, true], [FixAction::Remove, false]], array_map(fn (Fix $fix) => [$fix->action, $fix->primary], $gone->fixes));

        $bullets = GapFinder::standard()->find($this->context('Gardens in [[check: 3 areas | from: Northumberland; Durham; Tyne Valley]].', ["Areas:\n- Northumberland\n- Durham\n- Tyne Valley"]))->ofKind(GapKind::Check)[0];
        $this->assertNull($bullets->meta['stale'], 'a bulleted list still there');
    }

    public function test_a_marker_whose_count_is_not_its_lists_offers_the_lists_count(): void
    {
        $gap = GapFinder::standard()->find($this->context('Gardens in [[check: 5 areas | from: Northumberland, Durham and the Tyne Valley]].'))->ofKind(GapKind::Check)[0];

        $this->assertSame(['count', 3, '3 areas'], [$gap->meta['stale'], $gap->meta['newCount'], $gap->meta['newValue']]);
        $this->assertSame('This says 5 areas, but “Northumberland, Durham and the Tyne Valley” has 3. Use “3 areas” instead?', $gap->message()->english());
        $this->assertSame([FixAction::Confirm, '3 areas'], [$gap->fixes[0]->action, $gap->fixes[0]->value]);
    }

    public function test_publishing_is_blocked_or_warned_until_the_count_is_checked(): void
    {
        $text = 'Gardens in '.self::MARKER.'.';
        $readiness = PublishReadiness::standard()->check($this->context($text, [self::BRIEF]));

        $this->assertTrue($readiness->blocked());
        $this->assertSame('1 thing to finish before this page goes live: Stats: value (a count to check: 3 areas).', $readiness->message()->english());
        $this->assertSame(['blocks.0.value' => 'Check “3 areas” before publishing.'], $readiness->byField());

        $warned = (new PublishReadiness(mode: OnPublish::Warn))->check($this->context($text));
        $this->assertTrue($warned->warns());
        $this->assertSame('Published with 1 thing still to finish: Stats: value (a count to check: 3 areas).', $warned->message()->english());

        foreach (['3 areas', '4 areas', ''] as $value) {
            $this->assertTrue(PublishReadiness::standard()->check($this->context(Markers::resolveCheck($text, self::MARKER, $value), [self::BRIEF]))->ready(), "resolved to '{$value}'");
        }
    }

    public function test_the_finish_this_page_strings_are_all_there(): void
    {
        foreach (['check', 'check-changed', 'check-gone', 'check-count', 'speech.check', 'fix.confirm', 'fix.use-count', 'fix.change', 'publish.item.check', 'publish.field.check', 'extras.counted.answer', 'extras.needs-review'] as $key) {
            $this->assertArrayHasKey($key, Message::strings());
        }

        foreach (Message::strings() as $key => $string) {
            $this->assertStringNotContainsStringIgnoringCase('guess', $string, "{$key} says guess");
        }
    }

    private function gapId(): string
    {
        return Gap::idFor(GapKind::Check, 'blocks/#s1/value', '3 areas');
    }

    /**
     * @param  list<string>  $sources
     */
    private function context(string $value, array $sources = []): GapContext
    {
        $schema = new Schema([
            new Field('blocks', Kind::Blocks, sets: ['stats' => new Set('Stats', '', [new Field('value', Kind::Text)])]),
        ]);

        return new GapContext(schema: $schema, entry: new EntryData(['blocks' => [['id' => 's1', 'type' => 'stats', 'value' => $value]]]), sources: $sources);
    }
}
