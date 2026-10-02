<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * An idea for an entry the site is missing, as the planner proposed it.
 */
final class SuggestedIdea
{
    /**
     * @param  string  $group  The handle of the group it is for; always one of those planned for.
     * @param  string|null  $kind  The handle of one of that group's kinds, or null.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $group,
        public readonly ?string $kind,
        public readonly string $why,
        public readonly string $notes,
    ) {}

    /**
     * As the addons' Studios returned it, with their own keys for the group
     * (`collection`, `section`, `resource`) and the kind (`type`, `kind`).
     *
     * @return array<string, string|null>
     */
    public function toArray(string $groupKey = 'collection', string $kindKey = 'type'): array
    {
        return [
            'title' => $this->title,
            $groupKey => $this->group,
            $kindKey => $this->kind,
            'why' => $this->why,
            'notes' => $this->notes,
        ];
    }
}
