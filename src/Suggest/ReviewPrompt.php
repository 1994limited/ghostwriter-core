<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;

/**
 * The review call's prompt for one batch: the page's units, its images,
 * the candidates (every free finding, for the model to keep or drop in
 * context, each with the heading it sits under and, when it is out of
 * date, the dated words), the site digest and what was dismissed or
 * already checked. No model,
 * no CMS: the same input always renders the same prompt (pinned by the
 * golden test).
 *
 * Link targets are never shown as stored: a link to a digest entry reads
 * `entry:e12`, any other link to the site `link:3`, so the model can only
 * point at entries it was shown, and core puts the real targets back
 * (restoreLinks()). Links to other sites are shown as they are.
 */
final class ReviewPrompt
{
    private const LINK = '/\]\(\s*<?([^\s)>]+)>?((?:\s+"[^"\n]*")?\s*)\)/u';

    /**
     * @param  array<int, string>  $attached  The finding ids whose picture is attached to the call.
     */
    public static function render(ReviewInput $input, ReviewBatch $batch, array $attached = []): string
    {
        $context = $input->context;
        $links = self::links($input, $batch);
        $attributes = [
            'title' => $context->gaps->entry->title(),
            'kind' => $input->writer->kind->title,
            'updated' => $context->updatedAt?->format('Y-m-d'),
            'today' => $context->now->format('Y-m-d'),
            'part' => $batch->total > 1 ? ($batch->index + 1).' of '.$batch->total : null,
        ];
        $lines = ['<page'.self::attributes($attributes).'>'];
        $limits = self::limits($input);

        foreach ($batch->units as $unit) {
            $lines[] = '<unit'.self::attributes([
                'id' => $unit->id,
                'field' => $input->label($unit),
                'type' => self::type($unit),
                'limit' => isset($limits[$unit->path->toString()]) ? (string) $limits[$unit->path->toString()] : null,
            ]).'>'.self::tokenise($unit->markdown, $links).'</unit>';
        }

        foreach ($batch->images as $id => $finding) {
            $lines[] = '<image'.self::attributes([
                'id' => $id,
                'field' => $finding->anchor->label,
                'file' => is_string($finding->meta['filename'] ?? null) ? $finding->meta['filename'] : null,
                'alt' => '',
                'attached' => in_array($finding->id, $attached, true) ? '1' : '0',
            ], true).'/>';
        }

        $lines[] = '</page>';
        $out = implode("\n", $lines);

        if ($batch->findings !== []) {
            $out .= "\n\n<candidates>\n".implode("\n", array_map(fn (string $number, Finding $finding) => self::finding($number, $finding, $input, $batch, $attached), array_keys($batch->findings), $batch->findings))."\n</candidates>";
        }

        if (! $input->digest->isEmpty()) {
            $out .= "\n\n<site>\n".$input->digest->render()."\n</site>";
        }

        $dismissed = self::dismissed($input, $batch);

        if ($dismissed !== []) {
            $out .= "\n\n<dismissed>\n".implode("\n", $dismissed)."\n</dismissed>";
        }

        return $out;
    }

    /**
     * The batch's link tokens: `entry:e12` for a digest entry, `link:N`
     * for any other target on the site, by the stored target.
     *
     * @return array<string, string> Stored target => token.
     */
    public static function links(ReviewInput $input, ReviewBatch $batch): array
    {
        $tokens = [];
        $n = 0;

        foreach ($batch->units as $unit) {
            if (preg_match_all(self::LINK, $unit->markdown, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $href) {
                if (isset($tokens[$href]) || preg_match('/^(?:https?:|mailto:|tel:|#)/i', $href) === 1) {
                    continue;
                }

                $id = $input->digest->idOf($href);
                $tokens[$href] = $id !== null ? 'entry:'.$id : 'link:'.(++$n);
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, string>  $tokens
     */
    public static function tokenise(string $markdown, array $tokens): string
    {
        return (string) preg_replace_callback(self::LINK, fn (array $m) => ']('.($tokens[$m[1]] ?? $m[1]).$m[2].')', $markdown);
    }

    /**
     * Stored targets back in place of the tokens in a reply's text, and
     * `entry:eN` for a digest entry the batch didn't show. Null when the
     * text links to a token that means nothing (`entry:e99`).
     *
     * @param  array<string, string>  $tokens
     */
    public static function restoreLinks(string $text, array $tokens, SiteDigest $digest): ?string
    {
        $back = array_flip($tokens);
        $unknown = false;
        $text = (string) preg_replace_callback(self::LINK, function (array $m) use ($back, $digest, &$unknown): string {
            $target = $m[1];

            if (isset($back[$target])) {
                $target = $back[$target];
            } elseif (preg_match('/^entry:(e\d+)$/', $target, $id) === 1) {
                $link = $digest->get($id[1])?->link;
                $unknown = $unknown || ! is_string($link);
                $target = is_string($link) ? $link : $target;
            } elseif (preg_match('/^link:\d+$/', $target) === 1) {
                $unknown = true;
            }

            return ']('.$target.$m[2].')';
        }, $text);

        return $unknown ? null : $text;
    }

    /**
     * SEO fields' limits, by path, from SeoFields.
     *
     * @return array<string, int>
     */
    private static function limits(ReviewInput $input): array
    {
        $limits = [];
        $context = $input->context->gaps;

        foreach ($context->seo?->in($context->schema, $context->entry) ?? [] as $field) {
            if ($field instanceof SeoField) {
                $limits[$field->path->toString()] = $field->limit;
            }
        }

        return $limits;
    }

    private static function type(Unit $unit): string
    {
        return match ($unit->kind) {
            UnitKind::Text, UnitKind::Prose => 'text',
            UnitKind::List => 'list',
            UnitKind::Row => 'row',
            default => 'rich',
        };
    }

    /**
     * One candidate's line: its number, category, unit, the heading it
     * sits under, its quote, what the check found and what to do with it
     * if it's kept.
     *
     * @param  array<int, string>  $attached
     */
    private static function finding(string $number, Finding $finding, ReviewInput $input, ReviewBatch $batch, array $attached): string
    {
        $where = self::unitOf($finding, $input, $batch);
        $heading = self::headingOf($finding, $input);
        $under = $heading !== null ? ' under "'.str_replace('"', "'", $heading).'"' : '';
        $quote = $finding->anchor->quote !== null && $finding->anchor->scope === AnchorScope::Range ? ' "'.str_replace('"', "'", $finding->anchor->quote->exact).'"' : '';
        $message = trim($finding->message->english());
        $line = "{$number} {$finding->category->value} {$where}{$under}{$quote}: ".$message.(preg_match('/[.!?]$/u', $message) === 1 ? '' : '.');

        if ($finding->category === Category::OutOfDate && is_string($finding->meta['phrase'] ?? null)) {
            return $line.' Dated words: "'.str_replace('"', "'", $finding->meta['phrase']).'". If kept: rewrite the whole sentence.';
        }

        if ($finding->kind === 'link-broken') {
            $candidates = array_values(array_filter(array_map(fn ($c) => is_array($c) ? $input->digest->idOf($c['value'] ?? null) : null, is_array($finding->meta['candidates'] ?? null) ? $finding->meta['candidates'] : [])));
            $line .= $candidates !== [] ? ' Candidate: '.implode(', ', $candidates).'.' : '';

            return $line.($finding->needs === Needs::Nothing ? ' If kept: the link alone, no words.' : ' If kept: write only if the words name the old page.');
        }

        if ($finding->kind === 'heading-long') {
            return $line.' If kept: write the heading shorter, under 60 characters, with the same meaning and no full stop.';
        }

        if ($finding->kind === 'few-links') {
            return $line.' If kept: no words for this one; add 1 to 3 link suggestions of your own (see Search: links), or drop it.';
        }

        if ($finding->kind === 'seo-missing') {
            $min = is_int($finding->meta['min'] ?? null) ? $finding->meta['min'] : 120;
            $max = is_int($finding->meta['max'] ?? null) ? $finding->meta['max'] : 155;

            return $line." If kept: write the whole description, {$min} to {$max} characters, from what the page says.";
        }

        if ($finding->kind === 'long-sentence') {
            return $line.' Keep only if it reads badly here; then write.';
        }

        if ($finding->anchor->scope === AnchorScope::Asset) {
            return $line.(in_array($finding->id, $attached, true) ? ' The picture is attached. If kept: write.' : ' The picture is not attached. If kept: no replacement.');
        }

        if ($finding->needs === Needs::Nothing) {
            return $line.' If kept: no words.';
        }

        return $line.($finding->needs === Needs::Editor ? ' If kept: ask.' : ' If kept: write.');
    }

    /** The heading a finding's words sit under in its field, if any. */
    private static function headingOf(Finding $finding, ReviewInput $input): ?string
    {
        $text = $input->context->textAt($finding->anchor->path->toString());

        if ($text === null || $finding->anchor->scope !== AnchorScope::Range || $finding->anchor->quote === null) {
            return null;
        }

        $match = (new QuoteFinder)->find($finding->anchor->quote, $text->plain, $finding->anchor->occurrence);

        return $match === null ? null : $text->headingBefore($match->offset);
    }

    /** The unit (or image) a finding is in, as the prompt numbers them. */
    private static function unitOf(Finding $finding, ReviewInput $input, ReviewBatch $batch): string
    {
        foreach ($batch->images as $id => $image) {
            if ($image->id === $finding->id) {
                return $id;
            }
        }

        $path = $finding->anchor->path->toString();
        $quote = $finding->anchor->quote !== null ? NormalisedText::string($finding->anchor->quote->exact, true) : null;
        $first = null;

        foreach ($batch->units as $unit) {
            $unitPath = $unit->path->toString();

            if ($unitPath === $path || str_starts_with($path, $unitPath.'/')) {
                $first ??= $unit->id;

                if ($quote === null || str_contains(NormalisedText::string($unit->markdown, true), $quote)) {
                    return $unit->id;
                }
            }
        }

        return $first ?? '-';
    }

    /**
     * What was dismissed, confirmed or checked fine on this entry, in the
     * batch's units: "voice u2 "bespoke"", "out-of-date u3 "…" (checked fine)".
     *
     * @return list<string>
     */
    private static function dismissed(ReviewInput $input, ReviewBatch $batch): array
    {
        $lines = [];

        foreach ($input->context->quieted->all() as $quiet) {
            if (new \DateTimeImmutable($quiet->until) <= $input->context->now) {
                continue;
            }

            $parts = explode('|', $quiet->key);

            if (count($parts) < 4) {
                continue;
            }

            foreach ($batch->units as $unit) {
                if ($unit->path->toString() === $parts[1] || str_starts_with($parts[1], $unit->path->toString().'/')) {
                    $lines[] = $parts[0].' '.$unit->id.($parts[2] !== '' ? ' "'.$parts[2].'"' : '').($quiet->state === Quiet::CHECKED ? ' (checked fine)' : '');

                    break;
                }
            }
        }

        return array_values(array_unique($lines));
    }

    /**
     * @param  array<string, ?string>  $attributes
     */
    private static function attributes(array $attributes, bool $keepEmpty = false): string
    {
        $out = '';

        foreach ($attributes as $name => $value) {
            if ($value === null || ($value === '' && ! $keepEmpty)) {
                continue;
            }

            $out .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }

        return $out;
    }
}
