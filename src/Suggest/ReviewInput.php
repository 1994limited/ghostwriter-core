<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;

/**
 * Everything one review reads:
 *
 * - `context`: the entry as the free checks read it (CheckContext), with
 *   the decisions that keep things quiet and the claim switch;
 * - `writer`: the voice guide and the kind (guidance and checklist), as
 *   the writer gets them;
 * - `findings`: the free findings (Findings::find()); those that need
 *   words or an answer are numbered f1, f2… and given to the call;
 * - `digest`: the site's other entries the call may cite or link to;
 * - `images`: thumbnails for MissingAlt findings, by finding id, at most
 *   four a call; each still passes ModelInputGuard in the Studio;
 * - `replyLanguage`: the language reasons are written in (the person's
 *   CP language); replacements follow the page;
 * - `cap`: the call's own suggestions beyond the findings, per call.
 *
 * Units are `Arrange\Units::fromEntry()`: ids u1, u2… in reading order,
 * for this review only. A page over WORDS_PER_CALL words is split into
 * several calls (batches()); calls() says how many before anything runs,
 * for the confirm.
 */
final class ReviewInput
{
    public const WORDS_PER_CALL = 6000;

    public const IMAGES_PER_CALL = 4;

    private ?Units $units = null;

    /** @var list<ReviewBatch>|null */
    private ?array $batches = null;

    public readonly SiteDigest $digest;

    /**
     * @param  array<int, Finding>  $findings
     * @param  array<string, Image>  $images
     * @param  int  $wordsPerCall  WORDS_PER_CALL; smaller only in tests.
     */
    public function __construct(
        public readonly CheckContext $context,
        public readonly WriterContext $writer,
        public readonly array $findings = [],
        ?SiteDigest $digest = null,
        public readonly array $images = [],
        public readonly string $replyLanguage = 'en',
        public readonly int $cap = 12,
        public readonly int $wordsPerCall = self::WORDS_PER_CALL,
    ) {
        $this->digest = $digest ?? new SiteDigest;
    }

    public function units(): Units
    {
        return $this->units ??= Units::fromEntry($this->context->gaps->entry, $this->context->gaps->schema, $this->context->gaps->richText);
    }

    /** How many calls the review will make: say it in the confirm before it runs. */
    public function calls(): int
    {
        return max(1, count($this->batches()));
    }

    /**
     * The findings the call is given, numbered: those that need words or
     * an answer.
     *
     * @return array<string, Finding>
     */
    public function numbered(): array
    {
        $numbered = [];

        foreach ($this->findings as $finding) {
            if ($finding->needs !== Needs::Nothing) {
                $numbered['f'.(count($numbered) + 1)] = $finding;
            }
        }

        return $numbered;
    }

    /**
     * @return list<ReviewBatch>
     */
    public function batches(): array
    {
        if ($this->batches !== null) {
            return $this->batches;
        }

        $units = array_values(array_filter($this->units()->all(), fn (Unit $unit) => $unit->kind !== UnitKind::Media && trim($unit->markdown) !== ''));
        /** @var list<list<Unit>> $groups A field's units, together. */
        $groups = [];
        $previous = null;

        foreach ($units as $unit) {
            $key = $unit->path->toString();

            if ($key === $previous && $groups !== []) {
                $groups[count($groups) - 1][] = $unit;
            } else {
                $groups[] = [$unit];
            }

            $previous = $key;
        }

        /** @var list<list<Unit>> $packed */
        $packed = [];
        $current = [];
        $words = 0;
        $budget = max(1, $this->wordsPerCall);

        foreach ($groups as $group) {
            $size = array_sum(array_map(fn (Unit $unit) => self::wordsIn($unit->markdown), $group));
            // A field that fits goes in whole; one that doesn't, unit by unit.
            $pieces = $size <= $budget ? [$group] : array_map(fn (Unit $unit) => [$unit], $group);

            foreach ($pieces as $piece) {
                $pieceWords = array_sum(array_map(fn (Unit $unit) => self::wordsIn($unit->markdown), $piece));

                if ($current !== [] && $words + $pieceWords > $budget) {
                    $packed[] = $current;
                    $current = [];
                    $words = 0;
                }

                array_push($current, ...$piece);
                $words += $pieceWords;
            }
        }

        $packed[] = $current;

        $findings = array_fill(0, count($packed), []);
        $images = array_fill(0, count($packed), []);
        $image = 0;

        foreach ($this->numbered() as $number => $finding) {
            $at = $this->batchOf($finding, $packed);

            if ($finding->anchor->scope === AnchorScope::Asset) {
                $images[$at]['i'.(++$image)] = $finding;
            }

            $findings[$at][$number] = $finding;
        }

        $total = count($packed);
        $this->batches = array_map(fn (array $list, int $i) => new ReviewBatch($i, $total, $list, $findings[$i], $images[$i]), $packed, array_keys($packed));

        return $this->batches;
    }

    /** The label of a field, as the guide shows it ("Hero: Eyebrow"). */
    public function label(Unit $unit): string
    {
        foreach ($this->context->texts() as $text) {
            if ($text->visit->path->toString() === $unit->path->toString() || str_starts_with($text->visit->path->toString(), $unit->path->toString().'/')) {
                return $unit->kind === UnitKind::Row ? (string) preg_replace('/: [^:]+$/', '', $text->visit->label) : $text->visit->label;
            }
        }

        return $unit->path->field();
    }

    public static function wordsIn(string $text): int
    {
        return count(NormalisedText::words($text));
    }

    /**
     * Which batch a finding belongs in: the one whose units hold its quote,
     * else the first with its field, else the first.
     *
     * @param  list<list<Unit>>  $packed
     */
    private function batchOf(Finding $finding, array $packed): int
    {
        $path = $finding->anchor->path->toString();
        $quote = $finding->anchor->quote !== null ? NormalisedText::string($finding->anchor->quote->exact, true) : null;
        $first = null;

        foreach ($packed as $i => $units) {
            foreach ($units as $unit) {
                $unitPath = $unit->path->toString();

                if ($unitPath !== $path && ! str_starts_with($path, $unitPath.'/')) {
                    continue;
                }

                $first ??= $i;

                if ($quote === null || str_contains(NormalisedText::string($unit->markdown, true), $quote)) {
                    return $i;
                }
            }
        }

        return $first ?? 0;
    }
}
