<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Images;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;

/**
 * Image requests and their rules, over an ImageRequestStore: a new request
 * clears those older than a day first; a request is its owner's alone; a
 * job's result is saved under the request's lock; one whose job stopped
 * without saying so shows as failed (CRA-2).
 */
final class ImageRequests
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly ImageRequestStore $store,
        private readonly Lock $lock,
        private readonly DomainOptions $options,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    /**
     * A new search (ImageRequest::FIND) or picture (::MAKE) for the person.
     * Start its job after.
     *
     * @param  array<string, mixed>  $details  The field it is for, the direction, in the addon's keys.
     */
    public function start(string $mode, Viewer $viewer, array $details = []): ImageRequest
    {
        $now = ($this->clock)();

        $this->store->clearOlderThan($now->modify('-'.ImageRequest::KEEP_FOR.' seconds'));

        return $this->store->save(ImageRequest::start($this->options->format, $mode, $viewer->id, $details, $now));
    }

    /**
     * The person's own request.
     *
     * @throws NotFound
     * @throws NotAllowed when it is someone else's
     */
    public function mine(string $id, Viewer $viewer): ImageRequest
    {
        $request = $this->find($id) ?? throw new NotFound('No such image request.');

        if (! $request->isOwnedBy($viewer)) {
            throw new NotAllowed('That image request is someone else’s.');
        }

        return $request;
    }

    /**
     * Any request, for its job (which runs as nobody).
     */
    public function find(string $id): ?ImageRequest
    {
        $request = $this->store->find($id);
        $request?->recoverIfStale($this->options, ($this->clock)());

        return $request;
    }

    /**
     * A change to the request as it stands now, under its lock. Null when
     * it has gone.
     *
     * @param  callable(ImageRequest): void  $change
     */
    public function change(string $id, callable $change): ?ImageRequest
    {
        return $this->lock->run('image:'.$id, function () use ($id, $change) {
            $request = $this->store->find($id);

            if ($request === null) {
                return null;
            }

            $change($request);

            return $this->store->save($request);
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function succeed(string $id, array $details = [], ?string $file = null): ?ImageRequest
    {
        return $this->change($id, fn (ImageRequest $request) => $request->succeed($details, $file));
    }

    public function fail(string $id, string $error): ?ImageRequest
    {
        return $this->change($id, fn (ImageRequest $request) => $request->fail($error));
    }

    public function putFile(string $id, string $which, StoredFile $file): void
    {
        $this->store->putFile($id, $which, $file);
    }

    public function file(string $id, string $which): ?StoredFile
    {
        return $this->store->file($id, $which);
    }
}
