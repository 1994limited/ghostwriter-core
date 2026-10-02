<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Downloader;

/**
 * Thumbnails by address, fetched side by side where the client allows and
 * each checked to be an image under its cap. The last CACHE are
 * remembered, so a check (Openverse's) and a ranking fetch each once.
 *
 * @internal Shared by StockSearch and its libraries; not part of core's public API.
 */
final class Thumbnails
{
    /** The largest thumbnail that will be downloaded. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** How many thumbnails are remembered. */
    private const CACHE = 120;

    /** @var array<string, array{content: string, mime: string}|null> */
    private array $thumbs = [];

    public function __construct(private readonly Downloader $downloader) {}

    /**
     * @template K of array-key
     *
     * @param  array<K, string>  $urls
     * @return array<K, string|null> The bytes; null for one that can't be had.
     */
    public function get(array $urls): array
    {
        $this->load($urls);

        return array_map(fn (string $url) => $this->thumbs[$url]['content'] ?? null, $urls);
    }

    /**
     * @param  array<int|string, string>  $urls
     */
    private function load(array $urls): void
    {
        $missing = array_values(array_unique(array_filter($urls, fn (string $url) => ! array_key_exists($url, $this->thumbs))));

        if ($missing === []) {
            return;
        }

        foreach ($this->downloader->images($missing, self::MAX_BYTES) as $i => $thumb) {
            $this->thumbs[$missing[$i]] = $thumb;
        }

        if (count($this->thumbs) > self::CACHE) {
            $this->thumbs = array_slice($this->thumbs, -self::CACHE, null, true);
        }
    }
}
