<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;

/**
 * A GuideStore in memory.
 */
final class InMemoryGuideStore implements GuideStore
{
    /** @var array<string, array{body: string, at: DateTimeImmutable}> */
    public array $guides = [];

    /** @var array<string, array<string, mixed>> */
    public array $stateRecords = [];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(private readonly Format $format, ?Closure $clock = null)
    {
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function guide(string $kind): Guide
    {
        return isset($this->guides[$kind]) ? new Guide($kind, $this->guides[$kind]['body'], $this->guides[$kind]['at']) : new Guide($kind);
    }

    public function saveGuide(Guide $guide): Guide
    {
        $this->guides[$guide->kind] = ['body' => Guide::normalise($guide->body), 'at' => ($this->clock)()];

        return $this->guide($guide->kind);
    }

    public function state(string $kind): GuideState
    {
        return isset($this->stateRecords[$kind]) ? GuideState::fromArray($this->stateRecords[$kind], $this->format) : GuideState::empty($this->format);
    }

    public function saveState(string $kind, GuideState $state): void
    {
        $this->stateRecords[$kind] = $state->toArray($this->format);
    }
}
