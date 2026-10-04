<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;

/**
 * The verifier call's prompt for one part of a review: the suggestions
 * the reviewer kept there that passed SuggestionValidator, numbered s1,
 * s2… across the review, each with its whole paragraph, the heading it
 * sits under, its field, why it was made and the site entry it cites. The
 * page's title, kind, last-updated date and today are on `<page>`; the
 * voice guide is in the instructions. No model, no CMS: the same input
 * always renders the same prompt.
 *
 * Link targets are shown as the reviewer saw them (`entry:e12`, `link:3`),
 * and put back by SuggestionValidator::verify().
 */
final class VerifyPrompt
{
    /**
     * @param  array<string, Suggestion>  $suggestions  By number: "s1"…
     */
    public static function render(ReviewInput $input, ReviewBatch $batch, array $suggestions): string
    {
        $context = $input->context;
        $tokens = self::tokens($input, $batch);
        $lines = ['<page'.self::attributes([
            'title' => $context->gaps->entry->title(),
            'kind' => $input->writer->kind->title,
            'updated' => $context->updatedAt?->format('Y-m-d'),
            'today' => $context->now->format('Y-m-d'),
            'part' => $batch->total > 1 ? ($batch->index + 1).' of '.$batch->total : null,
        ]).'>'];

        foreach ($suggestions as $number => $suggestion) {
            [$paragraph, $heading] = self::paragraph($suggestion, $input);
            $lines[] = '<suggestion'.self::attributes([
                'id' => $number,
                'category' => $suggestion->category->value,
                'field' => $suggestion->anchor->label,
                'under' => $heading,
            ]).'>';

            if ($suggestion->anchor->scope === AnchorScope::Asset) {
                $lines[] = '<image'.self::attributes(['file' => $suggestion->anchor->asset?->filename()]).'/>';
            } else {
                $lines[] = '<paragraph>'.ReviewPrompt::tokenise($paragraph, $tokens).'</paragraph>';
            }

            if ($suggestion->anchor->quote !== null) {
                $lines[] = '<quote>'.$suggestion->anchor->quote->exact.'</quote>';
            }

            if ($suggestion->replacement !== null) {
                $lines[] = '<replacement>'.ReviewPrompt::tokenise($suggestion->replacement, $tokens).'</replacement>';
            }

            foreach ($suggestion->alternatives as $alternative) {
                $lines[] = '<alternative>'.ReviewPrompt::tokenise($alternative, $tokens).'</alternative>';
            }

            if ($suggestion->fact !== null) {
                $lines[] = '<ask>'.$suggestion->fact->ask.'</ask>';
                $lines[] = '<template>'.$suggestion->fact->template.'</template>';

                if ($suggestion->fact->without !== null) {
                    $lines[] = '<without>'.$suggestion->fact->without.'</without>';
                }
            }

            if ($suggestion->link !== null) {
                $id = $input->digest->idOf($suggestion->link->target);
                $lines[] = '<link>'.($id !== null ? 'entry:'.$id.' ' : '').'"'.$suggestion->link->title.'"</link>';
            }

            $why = trim($suggestion->reason->text) !== '' ? trim($suggestion->reason->text) : trim($suggestion->reason->message?->english() ?? '');

            if ($why !== '') {
                $lines[] = '<why>'.$why.'</why>';
            }

            $cited = self::cited($suggestion, $input);

            if ($cited !== null) {
                $lines[] = '<source>'.$cited.'</source>';
            }

            if ($suggestion->replacement === null && $suggestion->fact === null) {
                $lines[] = '<note>No words to check: keep or drop only.</note>';
            }

            $lines[] = '</suggestion>';
        }

        $lines[] = '</page>';

        return implode("\n", $lines);
    }

    /**
     * The batch's link tokens, as the reviewer saw them, and `entry:eN`
     * for every digest entry.
     *
     * @return array<string, string> Stored target => token.
     */
    public static function tokens(ReviewInput $input, ReviewBatch $batch): array
    {
        $tokens = ReviewPrompt::links($input, $batch);

        foreach ($input->digest->all() as $id => $entry) {
            if (is_scalar($entry->link) && ! isset($tokens[(string) $entry->link])) {
                $tokens[(string) $entry->link] = 'entry:'.$id;
            }
        }

        return $tokens;
    }

    /**
     * The paragraph (block) a suggestion's words are in, as plain text, and
     * the heading it sits under.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function paragraph(Suggestion $suggestion, ReviewInput $input): array
    {
        $text = $input->context->textAt($suggestion->anchor->path->toString());

        if ($text === null) {
            return [$suggestion->anchor->quote->exact ?? '', null];
        }

        if ($suggestion->anchor->scope !== AnchorScope::Range || $suggestion->anchor->quote === null) {
            return [$text->plain, null];
        }

        $match = (new QuoteFinder)->find($suggestion->anchor->quote, $text->plain, $suggestion->anchor->occurrence);
        $block = $match !== null ? $text->blockAt($match->offset) : null;

        return [$block !== null ? $text->block($block) : $suggestion->anchor->quote->exact, $match !== null ? $text->headingBefore($match->offset) : null];
    }

    /** The site entry a suggestion cites (or links to), as the digest gives it. */
    private static function cited(Suggestion $suggestion, ReviewInput $input): ?string
    {
        $id = null;

        if ($suggestion->reason->source === ReasonSource::SiteEntry && $suggestion->reason->entry !== null) {
            foreach ($input->digest->all() as $digestId => $entry) {
                if ($entry->entry?->key() === $suggestion->reason->entry || (is_scalar($entry->link) && (string) $entry->link === $suggestion->reason->entry)) {
                    $id = $digestId;
                }
            }
        }

        $id ??= $suggestion->link !== null ? $input->digest->idOf($suggestion->link->target) : null;

        $entry = $id !== null ? $input->digest->get($id) : null;

        return $entry === null ? null : $id.' "'.str_replace('"', "'", $entry->title).'"'.(trim($entry->summary) !== '' ? ': '.trim((string) preg_replace('/\s+/u', ' ', $entry->summary)) : '');
    }

    /**
     * @param  array<string, ?string>  $attributes
     */
    private static function attributes(array $attributes): string
    {
        $out = '';

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $out .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }

        return $out;
    }
}
