<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Watches;

/**
 * Scores one entry for the Content to revisit list from the free checks
 * alone: no model, no request, and no Overlaps (too slow per entry).
 *
 * The AgePolicy given here scores the row; build each snapshot's
 * CheckContext with the same one, so past years in dated groups are read
 * the same way.
 */
final class RevisitScanner
{
    private readonly Findings $findings;

    private readonly Priority $priority;

    /**
     * @param  array<int, string>  $ownHosts  The site's own hosts: links to them aren't "other sites".
     */
    public function __construct(
        private readonly AgePolicy $age = new AgePolicy,
        ?Findings $findings = null,
        ?Priority $priority = null,
        private readonly array $ownHosts = [],
    ) {
        $this->findings = ($findings ?? Findings::standard())->without('overlap');
        $this->priority = $priority ?? new Priority;
    }

    /**
     * The entry's row. The previous row, if any, gives the external link
     * results and the snooze, which a scan keeps.
     */
    public function scan(EntrySnapshot $entry, DateTimeImmutable $now, ?RevisitRow $previous = null): RevisitRow
    {
        $context = $entry->context->at($now);
        $report = $this->findings->report($context);
        $links = Links::in($context, $this->ownHosts);
        $external = [];

        foreach ($links['external'] as $url) {
            $external[$url] = $previous->external[$url] ?? $context->external[$url] ?? new LinkResult($url, LinkStatus::Unknown);
        }

        $reasons = $this->reasons($report->findings, $report->leftovers(), $report->emptyFields(), $external);
        $updatedAt = $context->updatedAt;

        if (($age = $this->ageReason($updatedAt, $now)) !== null) {
            $reasons[] = $age;
        }

        return new RevisitRow(
            $entry->ref,
            $entry->title,
            $entry->editUrl,
            $updatedAt?->format(DATE_ATOM),
            $reasons,
            $this->priority->score($reasons, $updatedAt, $now, $this->age, $entry->ref->group),
            $now->format(DATE_ATOM),
            self::hash($context->texts()),
            $this->watch($context, $now),
            $links['internal'],
            $external,
            $previous?->snoozedUntil,
        );
    }

    /**
     * The row scored again for today without reading the entry: its age
     * and its external links change; every other reason is as scanned.
     */
    public function rescore(RevisitRow $row, DateTimeImmutable $now): RevisitRow
    {
        $updatedAt = $row->updatedAt !== null ? new DateTimeImmutable($row->updatedAt) : null;
        $reasons = array_values(array_filter($row->reasons, fn (RevisitReason $reason) => ! in_array($reason->kind, [ReasonKind::Age, ReasonKind::ExternalLink], true)));

        if (($broken = count($row->brokenExternal())) > 0) {
            $reasons[] = new RevisitReason(ReasonKind::ExternalLink, $broken);
        }

        if (($age = $this->ageReason($updatedAt, $now)) !== null) {
            $reasons[] = $age;
        }

        return $row->with(reasons: $reasons, score: $this->priority->score($reasons, $updatedAt, $now, $this->age, $row->entry->group));
    }

    /**
     * @param  list<Finding>  $findings
     * @param  array<string, LinkResult>  $external
     * @return list<RevisitReason>
     */
    private function reasons(array $findings, int $leftovers, int $empty, array $external): array
    {
        $byKind = [];

        foreach ($findings as $finding) {
            $byKind[$finding->kind][] = $finding;
        }

        $reasons = [];
        $quote = fn (string $kind) => isset($byKind[$kind][0]) ? $byKind[$kind][0]->anchor->quote?->exact : null;
        $count = fn (string ...$kinds) => array_sum(array_map(fn (string $kind) => count($byKind[$kind] ?? []), $kinds));

        if ($leftovers > 0) {
            $reasons[] = new RevisitReason(ReasonKind::Leftover, $leftovers);
        }

        foreach (['closing-date' => ReasonKind::ClosingDate, 'link-broken' => ReasonKind::BrokenLink, 'past-year' => ReasonKind::PastYear] as $kind => $reason) {
            if ($count($kind) > 0) {
                $reasons[] = new RevisitReason($reason, $count($kind), $quote($kind));
            }
        }

        if (($broken = count(array_filter($external, fn (LinkResult $result) => $result->isBroken()))) > 0) {
            $reasons[] = new RevisitReason(ReasonKind::ExternalLink, $broken);
        }

        if ($count('relative-time') > 0) {
            $written = $byKind['relative-time'][0]->meta['written'] ?? null;
            $reasons[] = new RevisitReason(ReasonKind::RelativeTime, $count('relative-time'), $quote('relative-time'), ['year' => is_string($written) ? (int) substr($written, 0, 4) : null]);
        }

        if (($emptyFields = $empty + $count('seo-empty')) > 0) {
            $reasons[] = new RevisitReason(ReasonKind::EmptyField, $emptyFields);
        }

        foreach (['stated-count' => ReasonKind::StatedCount, 'missing-alt' => ReasonKind::MissingAlt, 'seo-length' => ReasonKind::SeoLength] as $kind => $reason) {
            if ($count($kind) > 0) {
                $reasons[] = new RevisitReason($reason, $count($kind), $quote($kind));
            }
        }

        return $reasons;
    }

    private function ageReason(?DateTimeImmutable $updatedAt, DateTimeImmutable $now): ?RevisitReason
    {
        if ($updatedAt === null || $this->age->share($updatedAt, $now) <= 0.0) {
            return null;
        }

        return new RevisitReason(ReasonKind::Age, 1, null, ['months' => AgePolicy::months($updatedAt, $now)]);
    }

    /**
     * @return list<string>
     */
    private function watch(CheckContext $context, DateTimeImmutable $now): array
    {
        $days = [];

        foreach ($this->findings->checks() as $check) {
            if ($check instanceof Watches) {
                array_push($days, ...$check->watch($context));
            }
        }

        // Relative time and counts start to count when the entry turns a year old.
        $updatedAt = $context->updatedAt;

        if ($updatedAt !== null && ($year = $updatedAt->modify('+12 months')) > $now) {
            $days[] = $year;
        }

        $days = array_values(array_unique(array_map(fn (DateTimeImmutable $day) => $day->format('Y-m-d'), array_filter($days, fn (DateTimeImmutable $day) => $day > $now))));
        sort($days);

        return $days;
    }

    /**
     * @param  list<CheckText>  $texts
     */
    private static function hash(array $texts): string
    {
        return substr(sha1(implode("\n", array_map(fn (CheckText $text) => $text->visit->path->toString().'='.$text->plain, $texts))), 0, 16);
    }
}
