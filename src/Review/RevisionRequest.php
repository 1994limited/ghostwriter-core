<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;

/**
 * One Apply: the threads in the run, each with what it may change, and
 * what the reviser works from. One call, whatever the number of threads
 * (up to Review::PER_APPLY). Studio::revise() sends it.
 *
 * The prompt is the current draft, then the comments, the units they may
 * change, the extra items they may change, and the chosen layout.
 */
final class RevisionRequest
{
    /**
     * @param  list<Thread>  $threads  In the run, by number.
     * @param  Plan|null  $plan  The chosen layout, for context and for a comment that asks to rearrange its block.
     * @param  array<int|string, string>  $names  User id => name, for "By Priya"; optional.
     */
    public function __construct(
        public readonly array $threads,
        public readonly Units $units,
        public readonly Extras $extras,
        public readonly ?Plan $plan,
        public readonly WriterContext $writer,
        public readonly Conversation $conversation,
        public readonly array $names = [],
    ) {}

    /** What the reviser is sent after its instructions. */
    public function prompt(): string
    {
        $draft = $this->conversation->draft ?? '';
        $parts = [];

        if (trim($draft) !== '') {
            $parts[] = "<current_draft>\n".trim($draft)."\n</current_draft>";
        }

        $parts[] = "<comments>\n".$this->commentLines()."\n</comments>";
        $parts[] = "<units>\n".$this->unitLines()."\n</units>";

        $extras = $this->extraLines();

        if ($extras !== '') {
            $parts[] = "<extras>\n{$extras}\n</extras>";
        }

        if ($this->plan !== null && $this->plan->fields !== []) {
            $parts[] = "<layout>\nThe page is laid out as \"{$this->plan->name}\":\n".$this->layoutLines()."\n</layout>";
        }

        return implode("\n\n", $parts);
    }

    /**
     * The units and extra items a thread may change.
     *
     * @return list<string>
     */
    public function editable(Thread $thread): array
    {
        return $thread->scope->editableUnits($this->units, array_keys($this->extras->items()));
    }

    private function commentLines(): string
    {
        $lines = [];

        foreach ($this->threads as $thread) {
            $scope = $thread->scope;
            $ids = array_values(array_filter($this->editable($thread), fn (string $id) => $scope->kind !== ScopeKind::Page));
            $on = match ($scope->kind) {
                ScopeKind::Page => 'On the whole page (any unit)',
                default => 'On '.($scope->label !== null ? '"'.$scope->label.'"' : 'a block').' ('.(count($ids) === 1 ? 'unit ' : 'units ').implode(', ', $ids).')',
            };

            if ($scope->quote !== null) {
                $on .= ', about: "'.$scope->quote->exact.'"';
            }

            $by = $this->names[(string) $thread->startedBy] ?? null;
            $lines[] = "{$thread->number}. {$on}.".($by !== null ? " By {$by}." : '');
            $earlier = $thread->lastAnswer();

            if ($earlier !== null) {
                $lines[] = '   '.self::indent('You first said, in reply to: '.$thread->comment()->body);
                $lines[] = '   '.self::indent('You replied: '.$earlier->body);
                $lines[] = '   Now:';
            }

            foreach ($thread->asks() as $note) {
                $lines[] = '   '.self::indent($note->body);
            }
        }

        return implode("\n", $lines);
    }

    private function unitLines(): string
    {
        $wanted = [];

        foreach ($this->threads as $thread) {
            foreach ($this->editable($thread) as $id) {
                $wanted[$id] = true;
            }
        }

        $lines = [];

        foreach ($this->units->all() as $unit) {
            if (isset($wanted[$unit->id]) && $unit->markdown !== '') {
                $lines[] = "{$unit->id}: |\n  ".str_replace("\n", "\n  ", $unit->markdown);
            }
        }

        return $lines === [] ? '(none)' : (string) preg_replace('/^ +$/m', '', implode("\n", $lines));
    }

    private function extraLines(): string
    {
        $wanted = [];

        foreach ($this->threads as $thread) {
            foreach ($this->editable($thread) as $id) {
                $wanted[$id] = true;
            }
        }

        $lines = [];

        foreach ($this->extras->all() as $extra) {
            foreach ($extra->items as $item) {
                if (isset($wanted[$item->id])) {
                    $parts = $item->parts === [] ? '' : ' ('.implode(', ', array_map(fn (string $name, string $value) => "{$name}: \"{$value}\"", array_keys($item->parts), $item->parts)).')';
                    $lines[] = "{$item->id} [{$extra->kind->value}] \"{$item->text}\"{$parts}";
                }
            }
        }

        return implode("\n", $lines);
    }

    private function layoutLines(): string
    {
        $lines = [];

        foreach ($this->plan->fields ?? [] as $handle => $blocks) {
            $lines[] = "{$handle}: ".self::blocks($blocks);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<PlanBlock>  $blocks
     */
    private static function blocks(array $blocks): string
    {
        return implode(', ', array_map(function (PlanBlock $block) {
            $own = [];

            foreach ($block->placements as $placement) {
                array_push($own, ...$placement->refs());
            }

            $children = '';

            foreach ($block->children as $field => $nested) {
                $children .= " ({$field}: ".self::blocks($nested).')';
            }

            return $block->type.' ['.implode(' ', $own).']'.$children;
        }, $blocks));
    }

    private static function indent(string $text): string
    {
        return str_replace("\n", "\n   ", trim($text));
    }
}
