<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * What Finish's **Suggest links** found for a page (PageLinks): the links
 * it proposes, in reading order, and why there are none when there are
 * none. The addon keeps it for the page view (a cache, by a token the
 * guide sends back with each check) and hands it to the gap finder
 * (GapContext::$proposals): each link still on the page and not yet
 * linked is a step (Gaps\Detectors\ProposedLinks), and with none left to
 * offer, "Link to your other pages" says so (FewLinks).
 *
 * `none` says why nothing was proposed: `no-candidates` (no page of the
 * site is close enough), `no-room` (too short, or links enough already),
 * `no-place` (no field here can take a link), `dropped` (the model's
 * picks failed the checks or the verifier). Empty when there are links.
 */
final class LinkProposals
{
    public const NO_CANDIDATES = 'no-candidates';

    public const NO_ROOM = 'no-room';

    public const NO_PLACE = 'no-place';

    public const DROPPED = 'dropped';

    /**
     * @param  list<LinkProposal>  $links
     */
    public function __construct(
        public readonly array $links = [],
        public readonly string $none = '',
        public readonly string $at = '',
    ) {}

    public static function none(string $why, ?string $at = null): self
    {
        return new self([], $why, $at ?? gmdate('Y-m-d\TH:i:s\Z'));
    }

    public function isEmpty(): bool
    {
        return $this->links === [];
    }

    /**
     * @return array{links: list<array<string, mixed>>, none: string, at: string}
     */
    public function toArray(): array
    {
        return ['links' => array_map(fn (LinkProposal $link) => $link->toArray(), $this->links), 'none' => $this->none, 'at' => $this->at];
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $links = [];

        foreach (is_array($array['links'] ?? null) ? $array['links'] : [] as $item) {
            if (is_array($item) && ($link = LinkProposal::fromArray($item)) !== null) {
                $links[] = $link;
            }
        }

        return new self($links, is_string($array['none'] ?? null) ? $array['none'] : '', is_string($array['at'] ?? null) ? $array['at'] : '');
    }
}
