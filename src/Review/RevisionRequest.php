<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;

/**
 * One Apply: the comments sent, each with what it may change, and what
 * the reviser works from. One call, whatever the number of comments (up
 * to Comments::PER_APPLY). Studio::revise() sends it.
 *
 * The prompt is the current draft, then the comments, the units they may
 * change, the extra items they may change, and the chosen layout.
 */
final class RevisionRequest
{
    /**
     * @param  list<Comment>  $comments  Sent together, by number.
     * @param  Plan|null  $plan  The chosen layout, for context and for a comment that asks to rearrange its block.
     * @param  array<int|string, string>  $names  User id => name, for "By Priya"; optional.
     */
    public function __construct(
        public readonly array $comments,
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
     * The units and extra items a comment may change.
     *
     * @return list<string>
     */
    public function editable(Comment $comment): array
    {
        return $comment->scope->editableUnits($this->units, array_keys($this->extras->items()));
    }

    private function commentLines(): string
    {
        $lines = [];

        foreach ($this->comments as $comment) {
            $scope = $comment->scope;
            $ids = array_values(array_filter($this->editable($comment), fn (string $id) => $scope->kind !== ScopeKind::Page));
            $on = match ($scope->kind) {
                ScopeKind::Page => 'On the whole page (any unit)',
                default => 'On '.($scope->label !== null ? '"'.$scope->label.'"' : 'a block').' ('.(count($ids) === 1 ? 'unit ' : 'units ').implode(', ', $ids).')',
            };

            if ($scope->quote !== null) {
                $on .= ', about: "'.$scope->quote->exact.'"';
            }

            $by = $comment->by !== null ? ($this->names[(string) $comment->by] ?? null) : null;
            $lines[] = "{$comment->number}. {$on}.".($by !== null ? " By {$by}." : '');

            foreach ($comment->asks() as $ask) {
                $lines[] = '   '.self::indent($ask);
            }
        }

        return implode("\n", $lines);
    }

    private function unitLines(): string
    {
        $wanted = [];

        foreach ($this->comments as $comment) {
            foreach ($this->editable($comment) as $id) {
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

        foreach ($this->comments as $comment) {
            foreach ($this->editable($comment) as $id) {
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
