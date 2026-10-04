<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use InvalidArgumentException;

/**
 * An entry, wherever it lives: its group (a collection or section handle,
 * a Filament resource), its ID, and its site (a Statamic site handle, a
 * Craft site ID, a Filament tenant key), where there are several.
 */
final class EntryRef
{
    public function __construct(
        public readonly string $group,
        public readonly int|string $id,
        public readonly int|string|null $site = null,
    ) {
        if ((string) $id === '') {
            throw new InvalidArgumentException('An entry reference needs an ID.');
        }
    }

    /** "pages:abc123@default", or "pages:abc123" with no site. */
    public function key(): string
    {
        return $this->group.':'.$this->id.($this->site !== null && $this->site !== '' ? '@'.$this->site : '');
    }

    public function is(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /**
     * @return array{group: string, id: int|string, site: int|string|null}
     */
    public function toArray(): array
    {
        return ['group' => $this->group, 'id' => $this->id, 'site' => $this->site];
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $id = $array['id'] ?? null;
        $site = $array['site'] ?? null;

        return new self(
            is_scalar($array['group'] ?? null) ? (string) $array['group'] : '',
            is_int($id) || is_string($id) ? $id : '',
            is_int($site) || is_string($site) ? $site : null,
        );
    }
}
