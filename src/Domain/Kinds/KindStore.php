<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds;

/**
 * Where an addon keeps the kinds of content it has been taught (Statamic's
 * YAML files, Craft's `type` documents, Filament's `ghostwriter_kinds`
 * rows), the kinds it has suggested for each group, and whether each group
 * is being studied.
 *
 * The general brief ("any:…") is never stored: the addon makes it with
 * ContentType::generic(), as it knows the group's name.
 *
 * The rules a store must keep are in tests/Contracts/KindStoreContract.php.
 */
interface KindStore
{
    /**
     * Every taught kind, by title.
     *
     * @return array<int, ContentType>
     */
    public function all(): array;

    public function find(string $handle): ?ContentType;

    /**
     * Adds it, or saves it over the kind with its handle.
     */
    public function save(ContentType $type): ContentType;

    public function delete(string $handle): void;

    /**
     * An empty list for a group never looked at.
     */
    public function suggestions(string $group): KindSuggestions;

    public function saveSuggestions(string $group, KindSuggestions $suggestions): void;

    /**
     * Idle for a group never studied.
     */
    public function analysis(string $group): Analysis;

    public function saveAnalysis(string $group, Analysis $analysis): void;
}
