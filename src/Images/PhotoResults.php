<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Photographs found for one place, in the order to offer them, and how they
 * were chosen:
 *
 * - `judged`: a model compared them with the page (and the references, when
 *   there were any) and left out clear misses. Only then are any `picked`
 *   (the shortlist, for a "Best match" badge).
 * - `withReferences`: the judging matched the style of the images already
 *   in that place, not only the subject.
 * - `noneFit`: the model found nothing that belongs, even after a second
 *   round of searches; the photos are the unranked results, to say so.
 * - `retried`: a second round of searches was run because nothing fitted.
 * - `terms`: every search run, in order, second round included.
 *
 * @implements IteratorAggregate<int, Photo>
 */
final class PhotoResults implements Countable, IteratorAggregate
{
    /**
     * @param  array<int, Photo>  $photos
     * @param  array<int, string>  $terms
     */
    public function __construct(
        public readonly array $photos,
        public readonly array $terms,
        public readonly bool $judged = false,
        public readonly bool $noneFit = false,
        public readonly bool $retried = false,
        public readonly bool $withReferences = false,
    ) {}

    /**
     * @return array<int, Photo> The shortlist the model picked; empty when it didn't judge.
     */
    public function picked(): array
    {
        return array_values(array_filter($this->photos, fn (Photo $photo) => $photo->picked));
    }

    public function first(): ?Photo
    {
        return $this->photos[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->photos === [];
    }

    public function count(): int
    {
        return count($this->photos);
    }

    /**
     * @return Traversable<int, Photo>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->photos);
    }

    /**
     * For JSON: the photos as Photo::toArray() gives them, and the flags.
     *
     * @return array{photos: array<int, array<string, mixed>>, terms: array<int, string>, judged: bool, none_fit: bool, retried: bool, with_references: bool}
     */
    public function toArray(): array
    {
        return [
            'photos' => array_map(fn (Photo $photo) => $photo->toArray(), $this->photos),
            'terms' => $this->terms,
            'judged' => $this->judged,
            'none_fit' => $this->noneFit,
            'retried' => $this->retried,
            'with_references' => $this->withReferences,
        ];
    }
}
