<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Images;

use DateTimeInterface;

/**
 * Where an addon keeps image requests and the pictures waiting beside them:
 * Statamic files under `storage/ghostwriter/images`, Craft its `state` and
 * `files` tables, Filament `image:<id>` state rows and the local disk.
 *
 * The rules a store must keep are in tests/Contracts/ImageRequestStoreContract.php.
 */
interface ImageRequestStore
{
    /**
     * Adds the request, or saves it over the one with its ID.
     */
    public function save(ImageRequest $request): ImageRequest;

    /**
     * Null for an ID that isn't one, or a request that has gone. The
     * request's `changedAt` is set to when it was last saved, where the
     * store knows.
     */
    public function find(string $id): ?ImageRequest;

    /**
     * Removes the request and its files.
     */
    public function delete(string $id): void;

    /**
     * Removes requests last saved before the cutoff, with their files, and
     * any file left older than it.
     *
     * @return int How many requests went.
     */
    public function clearOlderThan(DateTimeInterface $cutoff): int;

    /**
     * Keeps a picture beside a request: StoredFile::MADE or ::SOURCE.
     */
    public function putFile(string $id, string $which, StoredFile $file): void;

    public function file(string $id, string $which): ?StoredFile;
}
