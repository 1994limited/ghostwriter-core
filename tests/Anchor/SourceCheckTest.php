<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\SourceCheck;
use PHPUnit\Framework\TestCase;

final class SourceCheckTest extends TestCase
{
    public function test_figures_are_compared_by_what_they_say(): void
    {
        $check = new SourceCheck;

        $this->assertSame([], $check->unsourced('4 visits a winter', ['Four visits between November and February']));
        $this->assertSame([], $check->unsourced('From £1,200', ['Plans start at £1.2k']));
        $this->assertSame(['£60'], $check->unsourced('Visits from £60', ['Four visits a winter']));
        $this->assertSame(['6'], $check->unsourced('A team of 6 designers', ['Our team of designers']));
    }

    public function test_quotations_must_be_in_a_source(): void
    {
        $check = new SourceCheck;
        $source = 'We used to clear everything in October.';

        $this->assertSame([], $check->unsourced('“We used to clear everything in October”', [$source]));
        $this->assertSame(['The best gardeners in the north'], $check->unsourced('Clients say "The best gardeners in the north"', [$source]));
    }

    public function test_names_must_be_in_a_source(): void
    {
        $check = new SourceCheck;
        $source = 'We work in Northumberland, Durham and the Tyne Valley.';

        $this->assertSame([], $check->unsourced('Visits across Durham, Northumberland and the Tyne Valley.', [$source]));
        $this->assertSame(['Cumbria'], $check->unsourced('Visits across Durham and Cumbria.', [$source]));
        $this->assertSame(['Royal Horticultural Society'], $check->unsourced('Approved by the Royal Horticultural Society, we work in Durham.', [$source]));
    }

    public function test_sentence_starts_headings_and_title_case_are_not_names(): void
    {
        $check = new SourceCheck;

        $this->assertSame([], $check->unsourced("## Who it suits\n\nGardens with borders. Every winter we visit. **Note:** Cut back.", ['']));
        $this->assertSame([], $check->unsourced('## Winter Care For Mixed Borders', ['']));
    }

    public function test_markers_and_link_targets_are_not_facts(): void
    {
        $this->assertSame([], (new SourceCheck)->unsourced('Visits from [[ask: price per visit]]. See [our garden](https://example.com/2023/Garden).', ['our garden']));
    }
}
