<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * LinkValidator's verdict on the `seo-editor` call's picks: the links that
 * can be made, in the model's order, and the ones dropped with the rule
 * each broke (for the log).
 */
final class ValidatedLinks
{
    /**
     * @param  list<PlacedLink>  $kept
     * @param  list<array{pick: LinkPick, rule: string}>  $dropped
     */
    public function __construct(
        public readonly array $kept = [],
        public readonly array $dropped = [],
    ) {}

    /**
     * The rule each dropped pick broke, by its words.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->dropped as $drop) {
            $rules["{$drop['pick']->unit}: {$drop['pick']->exact}"] = $drop['rule'];
        }

        return $rules;
    }

    /**
     * Only the kept links whose id isn't in $drop (the verifier's verdicts).
     *
     * @param  array<string, string>  $drop  Link id => why.
     */
    public function without(array $drop): self
    {
        $kept = [];
        $dropped = $this->dropped;

        foreach ($this->kept as $link) {
            if (isset($drop[$link->id])) {
                $dropped[] = ['pick' => $link->pick, 'rule' => 'verifier: '.$drop[$link->id]];
            } else {
                $kept[] = $link;
            }
        }

        return new self($kept, $dropped);
    }
}
