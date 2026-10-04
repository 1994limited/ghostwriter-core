<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use DateTimeImmutable;
use Throwable;

/**
 * What one daily pass does to a site's link rows (SEO layer §7.1, "Keeping
 * it fresh" and "Size and cost"), worked out from the pages the addon's
 * link source lists (keys and dates only, nothing read in full) and the
 * rows it has:
 *
 * - the caps: per group, key pages and then the most recently updated, at
 *   most GROUP_ROWS (5,000); per site, at most SITE_ROWS (20,000);
 * - write: pages with no row, a row of the other scope, a row older than
 *   the page, a row written before its group was marked (a route or a tree
 *   changed) or before the weekly full pass began; key pages first, then
 *   the newest, at most PER_RUN (5,000) a run, so the first build of a big
 *   site is spread over several nights;
 * - forget: rows whose page is gone, no longer linkable or over a cap;
 * - over: groups over their cap, with their size, for the developer note.
 *
 * The addon writes `write` in chunks of CHUNK (500).
 */
final class LinkPlan
{
    /**
     * @param  list<string>  $write  Page keys to write, in order.
     * @param  list<string>  $forget  Row keys to forget.
     * @param  array<string, int>  $over  Group => how many linkable pages it has, for groups over the cap.
     * @param  int  $pending  Writes left for the next run.
     */
    public function __construct(
        public readonly array $write = [],
        public readonly array $forget = [],
        public readonly array $over = [],
        public readonly int $pending = 0,
    ) {}

    /**
     * @param  iterable<array{key: string, group: string, updated?: ?string, key_page?: bool}>  $pages  Every linkable page of the site's routable groups outside Ghostwriter's own.
     * @param  array<string, array{group?: string, scope?: string, updated?: ?string, indexed?: ?string}>  $rows  The site's link rows (in any group), and its full rows in groups that aren't Ghostwriter's (any more), by key.
     * @param  array<string, string>  $marked  Group => when it was marked (ISO).
     * @param  string|null  $fullFrom  When the current full pass began (ISO): rows written before are written again.
     */
    public static function make(
        iterable $pages,
        array $rows,
        array $marked = [],
        ?string $fullFrom = null,
        int $perRun = LinkCandidates::PER_RUN,
        int $groupCap = LinkCandidates::GROUP_ROWS,
        int $siteCap = LinkCandidates::SITE_ROWS,
    ): self {
        $byGroup = [];

        foreach ($pages as $page) {
            $byGroup[$page['group']][] = $page;
        }

        $order = fn (array $a, array $b) => [(bool) ($b['key_page'] ?? false), self::time($b['updated'] ?? null), $a['key']] <=> [(bool) ($a['key_page'] ?? false), self::time($a['updated'] ?? null), $b['key']];
        $kept = [];
        $over = [];

        foreach ($byGroup as $group => $list) {
            usort($list, $order);

            if (count($list) > $groupCap) {
                $over[(string) $group] = count($list);
            }

            array_push($kept, ...array_slice($list, 0, max(0, $groupCap)));
        }

        usort($kept, $order);

        if (count($kept) > $siteCap) {
            foreach (array_slice($kept, $siteCap) as $dropped) {
                $over[$dropped['group']] ??= count($byGroup[$dropped['group']]);
            }

            $kept = array_slice($kept, 0, max(0, $siteCap));
        }

        $keep = [];
        $write = [];
        $full = self::time($fullFrom);

        foreach ($kept as $page) {
            $keep[$page['key']] = true;
            $row = $rows[$page['key']] ?? null;
            $indexed = self::time($row['indexed'] ?? null);

            if ($row === null
                || ($row['scope'] ?? 'link') !== 'link'
                || self::time($page['updated'] ?? null) > self::time($row['updated'] ?? null)
                || (isset($marked[$page['group']]) && $indexed < self::time($marked[$page['group']]))
                || ($full > 0 && $indexed < $full)) {
                $write[] = $page['key'];
            }
        }

        $forget = array_values(array_map('strval', array_keys(array_diff_key($rows, $keep))));
        ksort($over);

        return new self(array_slice($write, 0, max(0, $perRun)), $forget, $over, max(0, count($write) - max(0, $perRun)));
    }

    /**
     * The writes in chunks of CHUNK.
     *
     * @return list<list<string>>
     */
    public function chunks(int $size = LinkCandidates::CHUNK): array
    {
        return array_chunk($this->write, max(1, $size));
    }

    private static function time(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }
}
