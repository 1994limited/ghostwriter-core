<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\ScopedEditCheck;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\SourceCheck;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftEditor;

/**
 * Checks what the reviser did for each comment, with no model (§6.4).
 * Each comment is checked on its own: one that breaks a rule keeps the
 * draft as it was and goes back to Not sent with a plain reply; the
 * others are applied.
 *
 * | Rule | |
 * |---|---|
 * | `scope` | It changed a unit (or extra item) outside the comment's, or its `exact` words aren't in the unit once. |
 * | `text-range` | A comment on some words changed text outside the sentences they're in. |
 * | `markers` | It lost a `[[ask: …]]`, `[[check: …]]` or `#gw-link:` link, or added one. An ask may be filled only with a fact the editor gave (in the comment or the brief, decision 4). |
 * | `link` | It added a link to another site, an email address or a phone number. |
 * | `facts` | It added a figure, quotation or name no source has (Anchor\SourceCheck): the unit before, the comment, the brief and answers, the draft, a shown entry. |
 * | `lost` | A unit was emptied, merged into another, split or turned into another kind: the draft's units aren't what they were. |
 * | `shape` | The text doesn't fit where it goes: an image, or a row with a different number of fields. |
 * | `missing` | The reply had nothing for the comment. |
 * | `size` | (a warning, still applied) The text is under 40% or over 160% of the words it had, and the comment didn't ask for length. |
 * | `layout` | (a warning, the text still applied) A new arrangement that the layout rules (PlanValidator) refuse. |
 */
final class RevisionValidator
{
    public const SCOPE = 'scope';

    public const TEXT_RANGE = 'text-range';

    public const MARKERS = 'markers';

    public const LINK = 'link';

    public const FACTS = 'facts';

    public const LOST = 'lost';

    public const SHAPE = 'shape';

    public const MISSING = 'missing';

    public const SIZE = 'size';

    public const LAYOUT = 'layout';

    public const MIN_RATIO = 0.4;

    public const MAX_RATIO = 1.6;

    /** Words in a comment that ask for a different length, so size isn't flagged. */
    public const LENGTH_WORDS = ['short', 'shorter', 'shorten', 'long', 'longer', 'lengthen', 'expand', 'cut', 'trim', 'tighten', 'condense', 'brief', 'briefer', 'one line', 'more detail', 'elaborate', 'less', 'more'];

    private readonly ScopedEditCheck $check;

    private readonly SourceCheck $sources;

    private readonly DraftEditor $editor;

    public function __construct(
        private readonly Schema $schema,
        private readonly EntryBuilder $builder = new EntryBuilder,
    ) {
        $this->sources = new SourceCheck;
        $this->check = new ScopedEditCheck($this->sources);
        $this->editor = new DraftEditor;
    }

    /**
     * One comment's item checked against the draft as it is now.
     *
     * @param  array<string, mixed>  $data  The draft's data now (with the earlier comments in this run applied).
     * @param  list<string>  $brief  The editor's own words besides the comment: the brief, the answers, their messages.
     * @param  list<string>  $sources  Everything else facts may come from: the draft, the entries shown.
     */
    public function check(Comment $comment, ?RevisionItem $item, array $data, Units $units, Extras $extras, array $brief = [], array $sources = []): Verdict
    {
        if ($item === null) {
            return new Verdict($comment, '', [self::MISSING]);
        }

        $editable = $comment->scope->editableUnits($units, array_keys($extras->items()));
        $comments = $comment->asks();
        $givers = [...$comments, ...$brief];
        $all = [...$givers, ...$sources];
        $rules = [];
        $warnings = [];
        $unsourced = [];
        $filled = [];
        $new = [];

        foreach ($item->touched() as $id) {
            if (! in_array($id, $editable, true)) {
                $rules[] = self::SCOPE;
            }
        }

        foreach ($item->units as $id => $text) {
            if ($units->get($id) !== null) {
                $new[$id] = trim($text);
            }
        }

        foreach ($item->replace as $replace) {
            $unit = $units->get($replace['unit']);

            if ($unit === null) {
                $rules[] = self::SCOPE;

                continue;
            }

            $text = $this->replaced($new[$unit->id] ?? $unit->markdown, $replace['exact'], $replace['with'], $comment->scope->quote);

            if ($text === null) {
                $rules[] = self::SCOPE;
            } else {
                $new[$unit->id] = $text;
            }
        }

        $data = $rules === [] ? $data : null;

        foreach ($new as $id => $after) {
            $unit = $units->get($id);

            if ($unit === null || $data === null) {
                continue;
            }

            if ($unit->kind === UnitKind::Media) {
                $rules[] = self::SHAPE;

                continue;
            }

            $quote = $comment->scope->kind === ScopeKind::Text && $comment->scope->units === [$id] ? $comment->scope->quote : null;
            $problems = $this->check->check($unit->markdown, $after, $all, self::MIN_RATIO, self::MAX_RATIO, $quote, mayFillAsks: true);

            foreach ($problems as $problem) {
                match ($problem) {
                    ScopedEditCheck::SCOPE => $rules[] = self::TEXT_RANGE,
                    ScopedEditCheck::SIZE => self::asksForLength($comments) ? null : $warnings[] = self::SIZE,
                    default => $rules[] = $problem,
                };
            }

            if (in_array(ScopedEditCheck::FACTS, $problems, true)) {
                array_push($unsourced, ...$this->sources->unsourced($after, [$unit->markdown, ...$all]));
            }

            $fills = $this->fills($unit->markdown, $after, $givers, $comment->by);

            if ($fills === null) {
                $rules[] = self::MARKERS;
            } elseif ($fills !== []) {
                $filled[$id] = $fills;
            }

            try {
                $data = $this->editor->set($data, $unit, $after);
            } catch (InvalidArgumentException) {
                $rules[] = self::SHAPE;
            }
        }

        if ($data !== null && $new !== [] && ! in_array(self::SHAPE, $rules, true) && ! self::sameUnits($units, Units::fromDraft($data, $this->schema))) {
            $rules[] = self::LOST;
        }

        $changedExtras = [];

        foreach ($item->extras as $id => $change) {
            $old = $extras->item($id);

            if ($old === null) {
                $rules[] = self::SCOPE;

                continue;
            }

            $changedExtras[$id] = $change;

            if ($change === null) {
                continue;
            }

            $before = trim($old->text."\n".implode("\n", $old->parts));
            $after = trim($change['text']."\n".implode("\n", $change['parts']));
            $problems = array_diff($this->check->check($before, $after, $all, 0.0, PHP_INT_MAX, null, mayFillAsks: true), [ScopedEditCheck::SIZE]);
            array_push($rules, ...$problems);

            if (in_array(ScopedEditCheck::FACTS, $problems, true)) {
                array_push($unsourced, ...$this->sources->unsourced($after, [$before, ...$all]));
            }

            $fills = $this->fills($before, $after, $givers, $comment->by);

            if ($fills === null) {
                $rules[] = self::MARKERS;
            } elseif ($fills !== []) {
                $filled[$id] = $fills;
            }
        }

        $rules = array_values(array_unique($rules));

        if ($rules !== []) {
            return new Verdict($comment, $item->reply, $rules, unsourced: array_values(array_unique($unsourced)));
        }

        return new Verdict($comment, $item->reply, [], $new, $changedExtras, $filled, $item->layout, array_values(array_unique($warnings)), [], $data);
    }

    /**
     * A new arrangement for a comment's block, checked as a layout is: the
     * chosen layout with the block or blocks holding the comment's units
     * replaced by the ones given. Null, with the reason in `$why`, when it
     * can't be used.
     *
     * @param  list<mixed>  $blocks  As the reviser gave them.
     * @param  array<string, mixed>  $data  The draft's data.
     */
    public function layout(Plan $chosen, array $blocks, Scope $scope, Units $units, Extras $extras, array $data, ?Pattern $pattern = null, ?string &$why = null): ?Plan
    {
        $ids = $scope->kind === ScopeKind::Page ? [] : $scope->units;
        $where = null;
        $range = [];

        foreach ($chosen->fields as $handle => $fieldBlocks) {
            foreach ($fieldBlocks as $i => $block) {
                $refs = array_map(fn (string $ref) => str_starts_with($ref, 'x') ? Extras::itemId($ref) : explode('#', $ref)[0], $block->refs());

                if (array_intersect($ids, $refs) !== []) {
                    if ($where !== null && $where !== $handle) {
                        $why = 'the comment spans more than one field';

                        return null;
                    }

                    $where = (string) $handle;
                    $range[] = $i;
                }
            }
        }

        if ($where === null || $range === [] || max($range) - min($range) + 1 !== count($range)) {
            $why = $where === null ? 'the comment\'s text isn\'t in this layout' : 'the comment\'s blocks aren\'t next to each other';

            return null;
        }

        $read = (new PlanReader)->plan([$where => $blocks], $this->schema, $chosen->id);
        $new = $read?->fields[$where] ?? [];

        if ($new === []) {
            $why = 'the new arrangement had no blocks this site has';

            return null;
        }

        $fields = $chosen->fields;
        array_splice($fields[$where], min($range), count($range), $new);
        $plan = $chosen->with(fields: $fields);
        $violations = (new PlanValidator($this->builder))->check($plan, $units, $extras, $data, $this->schema, $pattern);

        if ($violations !== []) {
            $why = implode('; ', array_map(fn ($violation) => $violation->message, $violations));

            return null;
        }

        return $plan;
    }

    /**
     * The text with `exact` replaced once: found once, or once given the
     * quote's context. Null when it isn't there exactly once.
     */
    private function replaced(string $text, string $exact, string $with, ?TextQuote $quote): ?string
    {
        if (mb_strlen($exact) > TextQuote::MAX_EXACT || trim($exact) === '') {
            return substr_count($text, $exact) === 1 ? str_replace($exact, $with, $text) : null;
        }

        $match = (new QuoteFinder)->find(new TextQuote($exact, $quote->prefix ?? '', $quote->suffix ?? ''), $text);

        if ($match === null || $match->fuzzy) {
            return null;
        }

        return mb_substr($text, 0, $match->offset).$with.mb_substr($text, $match->offset + $match->length);
    }

    /**
     * The asks the edit filled, each with what it was filled with; null
     * when one was filled with something the editor didn't give (or the
     * value can't be told).
     *
     * @param  list<string>  $givers  The editor's words: the comment and the brief.
     * @return list<array{ask: string, value: string, by: int|string|null}>|null
     */
    private function fills(string $before, string $after, array $givers, int|string|null $by): ?array
    {
        $remaining = array_count_values(array_map(fn (array $ask) => Markers::normaliseHint($ask['hint']), Markers::asks($after)));
        $fills = [];

        foreach (Markers::asks($before) as $ask) {
            $key = Markers::normaliseHint($ask['hint']);

            if (($remaining[$key] ?? 0) > 0) {
                $remaining[$key]--;

                continue;
            }

            $value = self::valueFor($before, $after, $ask['offset'], strlen($ask['match']));

            if ($value === null || ! $this->given($value, $givers)) {
                return null;
            }

            $fills[] = ['ask' => $ask['hint'], 'value' => $value, 'by' => $by];
        }

        return $fills;
    }

    /**
     * What took a marker's place: the text between the words just before
     * it and the words just after it, found again in the new text.
     */
    private static function valueFor(string $before, string $after, int $offset, int $length): ?string
    {
        $head = substr($before, 0, $offset);
        $tail = substr($before, $offset + $length);
        $lead = mb_substr(ltrim((string) preg_replace('/^.*[\n.!?]\s*/su', '', $head)), -24);
        $follow = mb_substr((string) preg_replace('/[\n].*$/su', '', $tail), 0, 24);
        $start = $lead === '' ? 0 : mb_strpos($after, $lead);

        if ($start === false) {
            return null;
        }

        $start += mb_strlen($lead);
        $end = trim($follow) === '' ? null : mb_strpos($after, $follow, $start);

        if (trim($follow) !== '' && $end === false) {
            return null;
        }

        $value = trim($end === null ? (string) preg_replace('/[\n].*$/su', '', mb_substr($after, $start)) : mb_substr($after, $start, $end - $start));

        return $value === '' || Markers::has($value) ? null : $value;
    }

    /**
     * Whether a value comes from the editor's words: every fact in it is
     * theirs, and most of its words are.
     *
     * @param  list<string>  $givers
     */
    private function given(string $value, array $givers): bool
    {
        if ($givers === [] || $this->sources->unsourced($value, $givers) !== []) {
            return false;
        }

        $words = array_filter(NormalisedText::words($value), fn (string $word) => mb_strlen($word) >= 3 || is_numeric($word));
        $theirs = array_flip(NormalisedText::words(implode("\n", $givers)));

        if ($words === []) {
            return preg_match('/\d/u', $value) === 1;
        }

        return count(array_filter($words, fn (string $word) => isset($theirs[$word]))) * 2 >= count($words);
    }

    /**
     * @param  list<string>  $comments
     */
    private static function asksForLength(array $comments): bool
    {
        $text = ' '.implode(' ', NormalisedText::words(implode("\n", $comments))).' ';

        foreach (self::LENGTH_WORDS as $word) {
            if (str_contains($text, " {$word} ")) {
                return true;
            }
        }

        return false;
    }

    /** Whether two drafts have the same units: each value split the same way, into the same kinds. */
    private static function sameUnits(Units $before, Units $after): bool
    {
        $shape = fn (Units $units) => array_map(fn (Unit $unit) => $unit->where().'|'.$unit->kind->value, $units->all());

        return $shape($before) === $shape($after);
    }
}
