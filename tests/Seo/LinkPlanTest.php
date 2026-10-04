<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkPlan;
use PHPUnit\Framework\TestCase;

final class LinkPlanTest extends TestCase
{
    public function test_a_first_build_writes_key_pages_then_the_newest_and_stops_at_the_run_limit(): void
    {
        $pages = [
            $this->page('old', 'pages', '2026-01-01'),
            $this->page('new', 'pages', '2026-09-01'),
            $this->page('contact', 'pages', '2025-01-01', key: true),
            $this->page('mid', 'pages', '2026-05-01'),
        ];
        $plan = LinkPlan::make($pages, [], perRun: 3);

        $this->assertSame(['contact', 'new', 'mid'], $plan->write);
        $this->assertSame(1, $plan->pending);
        $this->assertSame([['contact', 'new'], ['mid']], $plan->chunks(2));
    }

    public function test_only_missing_stale_marked_or_other_scope_rows_are_written_again(): void
    {
        $pages = [
            $this->page('fresh', 'pages', '2026-09-01'),
            $this->page('stale', 'pages', '2026-09-05'),
            $this->page('missing', 'pages', '2026-09-01'),
            $this->page('was-full', 'pages', '2026-09-01'),
            $this->page('marked', 'products', '2026-01-01'),
        ];
        $rows = [
            'fresh' => $this->row('pages', '2026-09-01', '2026-09-02'),
            'stale' => $this->row('pages', '2026-09-01', '2026-09-02'),
            'was-full' => $this->row('pages', '2026-09-01', '2026-09-02', 'full'),
            'marked' => $this->row('products', '2026-01-01', '2026-09-02'),
            'gone' => $this->row('pages', '2026-01-01', '2026-09-02'),
        ];
        $plan = LinkPlan::make($pages, $rows, marked: ['products' => '2026-09-03']);

        $this->assertEqualsCanonicalizing(['stale', 'missing', 'was-full', 'marked'], $plan->write);
        $this->assertSame(['gone'], $plan->forget);
        $this->assertSame([], $plan->over);
    }

    public function test_a_full_pass_writes_every_row_written_before_it_began(): void
    {
        $plan = LinkPlan::make([$this->page('a', 'pages', '2026-01-01'), $this->page('b', 'pages', '2026-01-01')], [
            'a' => $this->row('pages', '2026-01-01', '2026-09-01'),
            'b' => $this->row('pages', '2026-01-01', '2026-09-08'),
        ], fullFrom: '2026-09-07');

        $this->assertSame(['a'], $plan->write);
    }

    public function test_a_group_over_its_cap_keeps_key_pages_then_the_newest_and_is_reported(): void
    {
        $pages = [
            $this->page('p1', 'products', '2026-01-01'),
            $this->page('p2', 'products', '2026-03-01'),
            $this->page('p3', 'products', '2026-02-01'),
            $this->page('shop', 'products', '2020-01-01', key: true),
        ];
        $plan = LinkPlan::make($pages, ['p1' => $this->row('products', '2026-01-01', '2026-09-01')], groupCap: 2);

        $this->assertSame(['shop', 'p2'], $plan->write);
        $this->assertSame(['p1'], $plan->forget, 'A row over the cap goes.');
        $this->assertSame(['products' => 4], $plan->over);
    }

    public function test_the_site_cap_keeps_key_pages_then_the_newest_across_groups(): void
    {
        $pages = [
            $this->page('a', 'pages', '2026-01-01'),
            $this->page('b', 'journal', '2026-05-01'),
            $this->page('c', 'journal', '2026-04-01'),
        ];
        $plan = LinkPlan::make($pages, [], siteCap: 2);

        $this->assertSame(['b', 'c'], $plan->write);
        $this->assertSame(['pages' => 1], $plan->over);
    }

    /**
     * @return array{key: string, group: string, updated: string, key_page: bool}
     */
    private function page(string $id, string $group, string $updated, bool $key = false): array
    {
        return ['key' => $id, 'group' => $group, 'updated' => $updated, 'key_page' => $key];
    }

    /**
     * @return array{group: string, updated: string, indexed: string, scope: string}
     */
    private function row(string $group, string $updated, string $indexed, string $scope = 'link'): array
    {
        return ['group' => $group, 'updated' => $updated, 'indexed' => $indexed, 'scope' => $scope];
    }
}
