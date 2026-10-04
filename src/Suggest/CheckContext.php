<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;

/**
 * What the free checks read: the entry as Finish this page reads it
 * (GapContext: schema, values, dialects and ports), the time, when the
 * entry was last saved, the site's language, and what is known besides.
 *
 * - `index`: the site's other entries, for Overlaps. Null in the revisit
 *   scan, which leaves Overlaps out.
 * - `entry`: which entry it is, for Overlaps and the age policy's groups.
 * - `age`: dated groups weigh past years less (AgePolicy).
 * - `options`: the claim-check switch (SuggestOptions).
 * - `quieted`: decisions that keep findings quiet (Quieted).
 * - `external`: the opt-in weekly check's last results for the entry's
 *   links to other sites, by address (RevisitRow::$external). A link
 *   found gone twice in a row is a Link finding.
 *
 * Use named arguments: the order may grow.
 */
final class CheckContext
{
    /** @var list<CheckText>|null */
    private ?array $texts = null;

    /**
     * @param  array<string, LinkResult>  $external
     */
    public function __construct(
        public readonly GapContext $gaps,
        public readonly DateTimeImmutable $now,
        public readonly ?DateTimeImmutable $updatedAt = null,
        public readonly string $language = 'en',
        public readonly ?EntryIndex $index = null,
        public readonly ?EntryRef $entry = null,
        public readonly AgePolicy $age = new AgePolicy,
        public readonly SuggestOptions $options = new SuggestOptions,
        public readonly Quieted $quieted = new Quieted,
        public readonly array $external = [],
    ) {}

    /** The same entry read at another time: what the revisit scan uses, so "now" is the scan's. */
    public function at(DateTimeImmutable $now): self
    {
        return new self($this->gaps, $now, $this->updatedAt, $this->language, $this->index, $this->entry, $this->age, $this->options, $this->quieted, $this->external);
    }

    /**
     * Every field holding text, in form order.
     *
     * @return list<CheckText>
     */
    public function texts(): array
    {
        if ($this->texts === null) {
            $texts = [];

            foreach (Walk::entry($this->gaps->schema, $this->gaps->entry) as $visit) {
                $text = Walk::text($visit, $this->gaps->richText);

                if ($text !== null && trim($text) !== '') {
                    $texts[] = new CheckText($visit, $text);
                }
            }

            $this->texts = $texts;
        }

        return $this->texts;
    }

    /** The text at a path, if it holds any. */
    public function textAt(string $path): ?CheckText
    {
        foreach ($this->texts() as $text) {
            if ($text->visit->path->toString() === $path) {
                return $text;
            }
        }

        return null;
    }

    /** The phrase lists for the site's language; null when core has none. */
    public function phrases(): ?Phrases
    {
        return Phrases::for($this->language);
    }

    /** Months since the entry was last saved; null when that isn't known. */
    public function monthsOld(): ?int
    {
        return $this->updatedAt === null ? null : AgePolicy::months($this->updatedAt, $this->now);
    }

    /** Whether the entry was last saved at least this many months ago. */
    public function isOlderThan(int $months): bool
    {
        $old = $this->monthsOld();

        return $old !== null && $old >= $months;
    }

    /** Whether the entry is in a dated group whose switch is on. */
    public function dated(): bool
    {
        return $this->entry !== null && $this->age->isDated($this->entry->group);
    }
}
