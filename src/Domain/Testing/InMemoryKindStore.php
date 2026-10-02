<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;

/**
 * A KindStore in memory, keeping stored shapes.
 */
final class InMemoryKindStore implements KindStore
{
    /** @var array<string, array<string, mixed>> */
    public array $records = [];

    /** @var array<string, array<string, mixed>> */
    public array $suggestionRecords = [];

    /** @var array<string, array<string, mixed>> */
    public array $analysisRecords = [];

    public function __construct(private readonly Format $format) {}

    public function all(): array
    {
        $types = [];

        foreach (array_keys($this->records) as $handle) {
            $types[] = $this->find((string) $handle);
        }

        $types = array_values(array_filter($types));
        usort($types, fn (ContentType $a, ContentType $b) => strcmp($a->title, $b->title));

        return $types;
    }

    public function find(string $handle): ?ContentType
    {
        return isset($this->records[$handle]) ? ContentType::fromArray($this->records[$handle], $this->format, $handle) : null;
    }

    public function save(ContentType $type): ContentType
    {
        $this->records[$type->handle] = $type->toArray($this->format);

        return $type;
    }

    public function delete(string $handle): void
    {
        unset($this->records[$handle]);
    }

    public function suggestions(string $group): KindSuggestions
    {
        return isset($this->suggestionRecords[$group]) ? KindSuggestions::fromArray($this->suggestionRecords[$group], $this->format) : KindSuggestions::empty($this->format);
    }

    public function saveSuggestions(string $group, KindSuggestions $suggestions): void
    {
        $this->suggestionRecords[$group] = $suggestions->toArray($this->format);
    }

    public function analysis(string $group): Analysis
    {
        return isset($this->analysisRecords[$group]) ? Analysis::fromArray($this->analysisRecords[$group]) : new Analysis;
    }

    public function saveAnalysis(string $group, Analysis $analysis): void
    {
        $this->analysisRecords[$group] = $analysis->toArray();
    }
}
