<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * A kind of content the kind finder thinks a group holds, with the entries
 * that show it, for a person to look over.
 */
final class SuggestedKind
{
    /**
     * @param  array<int, int|string>  $examples  Two to six of the samples' IDs, as the samples gave them.
     * @param  string|null  $variant  The blueprint or entry type handle every example shares; null when they differ or none was given.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $why,
        public readonly array $examples,
        public readonly ?string $variant = null,
    ) {}

    /**
     * As the addons' Studios returned it: `title`, `description`, `why`,
     * `examples`, plus the variant under the key given (Statamic
     * `blueprint`, Craft `entryType`), or no variant key when null.
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $variantKey = null): array
    {
        $out = ['title' => $this->title, 'description' => $this->description, 'why' => $this->why, 'examples' => $this->examples];

        if ($variantKey !== null) {
            $out[$variantKey] = $this->variant;
        }

        return $out;
    }
}
