<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\CountedList;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\ListCounter;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * `[[check: 3 areas | from: …]]` in any text: a count Ghostwriter made
 * from a list, for the editor to confirm. "I counted 3 areas from
 * “Northumberland, Durham and the Tyne Valley”. Is that right?"
 *
 * - **Looks right** (`confirm`, primary) replaces the marker with its value.
 * - **Change it** (`change`) is an editable value, prefilled.
 * - **Remove it** (`remove`) takes the count out.
 *
 * It is **stale** when its list has changed since it was counted, and then
 * says so and offers the new count (`meta.stale`, `newCount`, `newValue`):
 *
 * - `count`: the marker's value isn't its own list's count (the marker
 *   was edited by hand);
 * - `changed`: the list is no longer in the sources (GapContext::$sources)
 *   but a list sharing items with it is: the count of that one is offered;
 * - `gone`: no list like it is in the sources any more; no count is
 *   offered.
 *
 * Without sources, only the first is checked. Nothing here calls a model.
 */
final class CheckMarkers implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::Check];
    }

    public function detect(GapContext $context): iterable
    {
        $sources = null;

        foreach (self::texts($context) as [$visit, $text]) {
            foreach (Markers::checks($text) as $check) {
                $sources ??= self::lists($context->sources);
                $state = self::state($check['value'], $check['list'], $sources, $context->sources !== []);
                $meta = ['match' => $check['match'], 'value' => $check['value'], 'list' => $check['list'], 'count' => ListCounter::numberIn($check['value'])] + $state;

                yield Gap::make(GapKind::Check, $visit->path, $visit->label, $check['value'], Markers::excerpt($text, $check['offset'], strlen($check['match'])), $check['occurrence'], self::fixes($check['value'], $state), $meta);
            }
        }
    }

    /**
     * Whether the count still stands, and what it should be when it doesn't.
     *
     * @param  list<CountedList>  $sources
     * @return array{stale: string|null, items?: list<string>, newCount?: int, newValue?: string, newList?: string, message?: string}
     */
    public static function state(string $value, string $list, array $sources, bool $haveSources = true): array
    {
        $number = ListCounter::numberIn($value);
        $own = ListCounter::fromMarker($list);
        $state = ['stale' => null] + ($own !== null ? ['items' => $own->items] : []);

        if ($own !== null && $number !== null && $own->count() !== $number) {
            return ['stale' => 'count', 'items' => $own->items, 'newCount' => $own->count(), 'newValue' => self::withCount($value, $own->count()), 'message' => 'gaps.check-count'];
        }

        if (! $haveSources || $own === null) {
            return $state;
        }

        $best = null;

        foreach ($sources as $candidate) {
            if ($candidate->sameItems($own)) {
                return $state;
            }

            if ($candidate->shared($own) > 0 && ($best === null || $candidate->shared($own) > $best->shared($own))) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return ['stale' => 'gone', 'message' => 'gaps.check-gone'] + $state;
        }

        return ['stale' => 'changed', 'items' => $own->items, 'newCount' => $best->count(), 'newValue' => self::withCount($value, $best->count()), 'newList' => $best->oneLine(), 'message' => 'gaps.check-changed'];
    }

    /**
     * Every list in the sources, with their own counts to check read as the
     * values they mark (a draft holds its markers' lists too).
     *
     * @param  array<int, string>  $sources
     * @return list<CountedList>
     */
    private static function lists(array $sources): array
    {
        $lists = [];

        foreach ($sources as $source) {
            array_push($lists, ...ListCounter::find(Markers::withoutChecks($source)));
        }

        return $lists;
    }

    /** "3 areas" with 4 in place of its number: "4 areas". */
    private static function withCount(string $value, int $count): string
    {
        $first = ListCounter::numbers($value)[0] ?? null;

        return $first === null ? (string) $count : substr_replace($value, (string) $count, $first['offset'], strlen($first['match']));
    }

    /**
     * @param  array{stale: string|null, newValue?: string}  $state
     * @return list<Fix>
     */
    private static function fixes(string $value, array $state): array
    {
        $fixes = match (true) {
            isset($state['newValue']) => [Fix::of(FixAction::Confirm, true, $state['newValue'], params: ['value' => $state['newValue']])->withLabel('gaps.fix.use-count')],
            $state['stale'] === 'gone' => [],
            default => [Fix::of(FixAction::Confirm, true, $value)],
        };

        return [...$fixes, Fix::of(FixAction::Change, $fixes === [], $value), Fix::of(FixAction::Remove)];
    }
}
