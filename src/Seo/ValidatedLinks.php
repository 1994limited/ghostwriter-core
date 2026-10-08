<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * LinkValidator's verdict on the `seo-editor` call's picks: the links that
 * can be made, in the model's order, and the ones dropped with the rule
 * each broke (for the log). After the verifier (LinkValidator::judged()),
 * also the links it gave better words, and whether they were taken.
 */
final class ValidatedLinks
{
    /**
     * @param  list<PlacedLink>  $kept
     * @param  list<array{pick: LinkPick, rule: string}>  $dropped
     * @param  list<array{from: string, to: string, rule: string|null, why: string}>  $anchored  The verifier's better words: rule null when taken, else the rule they broke (the first words kept).
     */
    public function __construct(
        public readonly array $kept = [],
        public readonly array $dropped = [],
        public readonly array $anchored = [],
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
     * The verifier's better words, for the log: "from → to", and "taken"
     * or the rule they broke.
     *
     * @return array<string, string>
     */
    public function anchors(): array
    {
        $out = [];

        foreach ($this->anchored as $change) {
            $out["{$change['from']} → {$change['to']}"] = $change['rule'] ?? 'taken';
        }

        return $out;
    }
}
