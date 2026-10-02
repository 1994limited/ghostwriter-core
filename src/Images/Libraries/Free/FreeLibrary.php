<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Images\Downloader;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * What the free libraries share: a photo is looked up again by ID before
 * its file is downloaded, https only, at most MAX_BYTES, and only a JPEG,
 * PNG or WebP (Downloader). Their photos are free to use and may be judged
 * by a model.
 */
abstract class FreeLibrary implements PhotoLibrary
{
    /** The largest photograph that will be downloaded. */
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** When the free libraries' API terms were last read. */
    public const TERMS_CHECKED_AT = '2026-10-02';

    protected readonly Downloader $downloader;

    public function __construct(HttpClients $http)
    {
        $this->downloader = new Downloader($http);
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::free(self::TERMS_CHECKED_AT, $this->creditRequired());
    }

    public function photo(string $id): Photo
    {
        $this->checkId($id);

        return $this->lookup($id)[0];
    }

    /**
     * The photo, looked up again by ID, and its file: https only, at most
     * MAX_BYTES, a JPEG, PNG or WebP.
     */
    public function fetch(string $id): PhotoFile
    {
        $this->checkId($id);

        [$photo, $data] = $this->lookup($id);
        [$file, $headers] = $this->fileAddress($photo, $data);

        if ($file === '' || ! $this->downloader->secure($file)) {
            throw new PhotoUnavailable('That photograph has no secure download address.');
        }

        $image = $this->downloader->image($file, self::MAX_BYTES, 60, $headers);

        return new PhotoFile($image['content'], $image['mime'], Downloader::IMAGE_TYPES[$image['mime']], $photo);
    }

    /**
     * The photo and the library's whole answer about it.
     *
     * @return array{0: Photo, 1: array<mixed>}
     *
     * @throws PhotoUnavailable
     */
    abstract protected function lookup(string $id): array;

    /**
     * Where the full file is, and the headers to send for it. The photo's
     * own `url` unless the library says otherwise.
     *
     * @param  array<mixed>  $data  The library's answer from lookup().
     * @return array{0: string, 1: array<string, string>}
     */
    protected function fileAddress(Photo $photo, array $data): array
    {
        return [(string) $photo->url, []];
    }

    protected function creditRequired(): bool
    {
        return false;
    }

    /**
     * @throws PhotoUnavailable
     */
    protected function checkId(string $id): void
    {
        if (! $this->available() || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new PhotoUnavailable('That photograph could not be found.');
        }
    }

    /**
     * A file name or camera code is not a title: "IMG_2034.JPG", "DSC01234",
     * "File:Old bridge.jpg" (which becomes "Old bridge").
     */
    protected function cleanTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = (string) preg_replace('/^file:\s*/i', '', trim($title));
        $title = trim((string) preg_replace('/\.(jpe?g|png|webp|gif|tiff?|heic)$/i', '', $title));

        if ($title === '' || preg_match('/^[a-z_\- ]{0,6}\d[\d_\- .]*$/i', $title)) {
            return null;
        }

        return $title;
    }

    /**
     * Tag names from a list of strings or of {name: ...} (Unsplash,
     * Openverse); machine-made Openverse tags (with an accuracy) go last.
     *
     * @return array<int, string>
     */
    protected function tagNames(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $people = [];
        $machine = [];

        foreach ($tags as $tag) {
            $name = is_array($tag) ? ($tag['name'] ?? $tag['title'] ?? null) : $tag;
            $name = is_scalar($name) ? Slug::clip((string) $name, 40) : '';

            if ($name !== '') {
                is_array($tag) && isset($tag['accuracy']) ? $machine[] = $name : $people[] = $name;
            }
        }

        return array_slice(array_values(array_unique([...$people, ...$machine])), 0, 12);
    }

    /**
     * @param  callable(array<mixed>): ?Photo  $map
     * @return array<int, Photo>
     */
    protected function each(mixed $items, callable $map): array
    {
        $photos = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && ($photo = $map($item)) !== null) {
                $photos[] = $photo;
            }
        }

        return $photos;
    }

    /**
     * A non-empty string at a path in decoded JSON, trimmed.
     *
     * @param  array<mixed>  $data
     */
    protected function string(array $data, string ...$path): ?string
    {
        $value = $data;

        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    protected function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
