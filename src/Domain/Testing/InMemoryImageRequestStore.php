<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;

/**
 * An ImageRequestStore in memory, with when each request and file was
 * saved.
 */
final class InMemoryImageRequestStore implements ImageRequestStore
{
    /** @var array<string, array{data: array<string, mixed>, at: DateTimeImmutable}> */
    public array $records = [];

    /** @var array<string, array{file: StoredFile, at: DateTimeImmutable}> */
    public array $files = [];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(private readonly Format $format, ?Closure $clock = null)
    {
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function save(ImageRequest $request): ImageRequest
    {
        $at = ($this->clock)();
        $this->records[$request->id] = ['data' => $request->toArray($this->format), 'at' => $at];
        $request->changedAt = $at;

        return $request;
    }

    public function find(string $id): ?ImageRequest
    {
        if (! isset($this->records[$id])) {
            return null;
        }

        $request = ImageRequest::fromArray($this->records[$id]['data'], $this->format);
        $request->changedAt = $this->records[$id]['at'];

        return $request;
    }

    public function delete(string $id): void
    {
        unset($this->records[$id], $this->files[$id.'-'.StoredFile::MADE], $this->files[$id.'-'.StoredFile::SOURCE]);
    }

    public function clearOlderThan(DateTimeInterface $cutoff): int
    {
        $gone = 0;

        foreach ($this->records as $id => $record) {
            if ($record['at'] < $cutoff) {
                $this->delete((string) $id);
                $gone++;
            }
        }

        foreach ($this->files as $key => $file) {
            if ($file['at'] < $cutoff) {
                unset($this->files[$key]);
            }
        }

        return $gone;
    }

    public function putFile(string $id, string $which, StoredFile $file): void
    {
        $this->files[$id.'-'.$which] = ['file' => $file, 'at' => ($this->clock)()];
    }

    public function file(string $id, string $which): ?StoredFile
    {
        return $this->files[$id.'-'.$which]['file'] ?? null;
    }
}
