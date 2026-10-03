<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\ScopedEditCheck;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use PHPUnit\Framework\TestCase;

final class ScopedEditCheckTest extends TestCase
{
    private const UNIT = "## Who it suits\n\nGardens with mixed borders and young trees. If your garden is mostly lawn and gravel, you probably don’t need it, and we’ll say so. Visits from [[ask: price per visit]].";

    public function test_a_replacement_of_a_quote_passes(): void
    {
        $this->assertSame([], (new ScopedEditCheck)->check('New for 2024: winter care visits', 'Every winter: care visits'));
    }

    public function test_a_change_inside_the_quoted_sentence_passes(): void
    {
        $after = str_replace('you probably don’t need it, and we’ll say so', 'it may not need us, and we’ll tell you honestly', self::UNIT);

        $this->assertSame([], (new ScopedEditCheck)->check(self::UNIT, $after, quote: new TextQuote('you probably don’t need it')));
    }

    public function test_a_change_outside_the_quoted_sentence_is_out_of_scope(): void
    {
        $after = str_replace('young trees', 'old trees', self::UNIT);

        $this->assertSame([ScopedEditCheck::SCOPE], (new ScopedEditCheck)->check(self::UNIT, $after, quote: new TextQuote('you probably don’t need it')));
        $this->assertSame([ScopedEditCheck::SCOPE], (new ScopedEditCheck)->check(self::UNIT, self::UNIT, quote: new TextQuote('not in the text at all')));
    }

    public function test_markers_are_kept(): void
    {
        $check = new ScopedEditCheck;
        $filled = str_replace('[[ask: price per visit]]', '£60', self::UNIT);

        $this->assertSame([ScopedEditCheck::MARKERS, ScopedEditCheck::FACTS], $check->check(self::UNIT, $filled));
        $this->assertSame([], $check->check(self::UNIT, $filled, ['It’s £60 a visit'], mayFillAsks: true), 'a comment gave the fact');
        $this->assertSame([ScopedEditCheck::MARKERS], $check->check('Talk to us.', 'Talk to us about [[ask: what]].', maxRatio: 3));
        $this->assertSame([], $check->check('Talk to us.', 'Talk to us about [[ask: what]].', maxRatio: 3, mayAddMarkers: true));
        $this->assertSame([ScopedEditCheck::MARKERS], $check->check('[Talk to us](#gw-link:contact-page) today.', 'Talk to us today.'));
    }

    public function test_no_new_external_links(): void
    {
        $check = new ScopedEditCheck;

        $this->assertSame([ScopedEditCheck::LINK], $check->check('See our winter work here.', 'See [our winter work](https://elsewhere.example/work) here.'));
        $this->assertSame([], $check->check('See [our work](https://northfold.example/work) here.', 'See [our winter work](https://northfold.example/work) here.'));
        $this->assertSame([], $check->check('See our winter work here.', 'See [our winter work](entry::abc) here.'));
    }

    public function test_no_unsourced_facts(): void
    {
        $this->assertSame([ScopedEditCheck::FACTS], (new ScopedEditCheck)->check('Our team of designers plans it.', 'Our team of 8 designers plans it.'));
        $this->assertSame([], (new ScopedEditCheck)->check('Our team of designers plans it.', 'Our team of 8 designers plans it.', ['How many designers? 8']));
    }

    public function test_size(): void
    {
        $check = new ScopedEditCheck;
        $before = 'Prune the shrubs that need it, wrap the tender plants and leave the seedheads standing.';

        $this->assertSame([ScopedEditCheck::SIZE], $check->check($before, 'Prune.'));
        $this->assertSame([ScopedEditCheck::SIZE], $check->check($before, $before.' '.$before));
        $this->assertSame([], $check->check($before, 'Prune.', minRatio: 0.05));
        $this->assertSame([], $check->check('Prune', 'Prune the roses and wrap the tender plants'), 'too short to measure');
    }
}
