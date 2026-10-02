<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

/**
 * An asset in the CMS, as each addon points at one:
 *
 * - **Statamic:** the container and the path (`assets::photos/rocks.jpg`).
 * - **Craft:** the asset's ID, with its volume and path for reading.
 * - **Filament:** the disk and the path (the form field holds the path).
 *
 * Two references are the same asset when their key() is: the ID where
 * there is one, else the volume and path.
 */
final class AssetRef
{
    public function __construct(
        public readonly string $volume,
        public readonly string $path,
        public readonly int|string|null $id = null,
    ) {}

    public static function statamic(string $container, string $path): self
    {
        return new self($container, ltrim($path, '/'));
    }

    public static function craft(int $assetId, string $volume = '', string $path = ''): self
    {
        return new self($volume, ltrim($path, '/'), $assetId);
    }

    public static function filament(string $disk, string $path): self
    {
        return new self($disk, ltrim($path, '/'));
    }

    /** "id:123", or "volume::path". */
    public function key(): string
    {
        return $this->id !== null && $this->id !== '' ? 'id:'.$this->id : $this->volume.'::'.$this->path;
    }

    public function is(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /** The file's name, for the checks on names such as "GettyImages-123.jpg". */
    public function filename(): string
    {
        return basename($this->path);
    }

    /**
     * @return array{volume: string, path: string, id: int|string|null}
     */
    public function toArray(): array
    {
        return ['volume' => $this->volume, 'path' => $this->path, 'id' => $this->id];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;

        return new self(
            is_scalar($data['volume'] ?? null) ? (string) $data['volume'] : '',
            is_scalar($data['path'] ?? null) ? (string) $data['path'] : '',
            is_int($id) || (is_string($id) && $id !== '') ? $id : null,
        );
    }
}
