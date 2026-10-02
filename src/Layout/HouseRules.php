<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

/**
 * What the model entries agree on, place by place, as HouseStyle::learn()
 * found it. Places are paths through the page builders:
 * "pageBuilder/spacer#1" is the second spacer, "pageBuilder/hero#0/
 * children/text#0" the text block inside the first hero. Markup goes by the
 * path without the counts ("pageBuilder/hero/children/text.richText").
 *
 * - `positions`: agreed values by place, then by field handle.
 * - `sequences`: the block types a builder nested in a block usually holds.
 * - `markup`: how rich text is dressed, by place, then by kind of element
 *   (the RichTextDialect's shapes).
 * - `links`: how often each kind of block has each link field set, as
 *   "pageBuilder/hero.buttonLink" => 0.75.
 */
final class HouseRules
{
    /**
     * @param  array<string, array<string, mixed>>  $positions
     * @param  array<string, array<int, string>>  $sequences
     * @param  array<string, array<string, array<string, mixed>>>  $markup
     * @param  array<string, float|int>  $links
     */
    public function __construct(
        public readonly array $positions = [],
        public readonly array $sequences = [],
        public readonly array $markup = [],
        public readonly array $links = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->positions === [] && $this->sequences === [] && $this->markup === [] && $this->links === [];
    }

    /**
     * The array the addons kept under a pattern's `house`.
     *
     * @return array{positions: array<string, array<string, mixed>>, sequences: array<string, array<int, string>>, markup: array<string, array<string, array<string, mixed>>>, links: array<string, float|int>}
     */
    public function toArray(): array
    {
        return ['positions' => $this->positions, 'sequences' => $this->sequences, 'markup' => $this->markup, 'links' => $this->links];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        /** @var array<string, array<string, mixed>> $positions */
        $positions = is_array($array['positions'] ?? null) ? $array['positions'] : [];
        /** @var array<string, array<int, string>> $sequences */
        $sequences = is_array($array['sequences'] ?? null) ? $array['sequences'] : [];
        /** @var array<string, array<string, array<string, mixed>>> $markup */
        $markup = is_array($array['markup'] ?? null) ? $array['markup'] : [];
        /** @var array<string, float|int> $links */
        $links = is_array($array['links'] ?? null) ? $array['links'] : [];

        return new self($positions, $sequences, $markup, $links);
    }
}
