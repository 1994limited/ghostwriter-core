<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Guides;

/**
 * Where an addon keeps the voice and image style guides (Statamic markdown
 * files in the project, Craft `guide` documents, Filament
 * `ghostwriter_guides` rows) and their screens' working state.
 *
 * The rules a store must keep are in tests/Contracts/GuideStoreContract.php.
 */
interface GuideStore
{
    /**
     * The guide (Guide::VOICE or Guide::IMAGERY); an empty one when none is
     * written, with no `updatedAt`.
     */
    public function guide(string $kind): Guide;

    /**
     * Saves the body, normalised (Guide::normalise()).
     */
    public function saveGuide(Guide $guide): Guide;

    /**
     * An idle state when nothing is stored.
     */
    public function state(string $kind): GuideState;

    public function saveState(string $kind, GuideState $state): void;
}
