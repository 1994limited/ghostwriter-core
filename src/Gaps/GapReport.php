<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

use Countable;

/**
 * What GapFinder found in an entry, in form order: what the pill counts
 * (gaps that block, prompt, or that the CMS requires) and the suggestions
 * after it.
 */
final class GapReport implements Countable
{
    /**
     * @param  list<Gap>  $gaps
     */
    public function __construct(private readonly array $gaps = []) {}

    /**
     * @return list<Gap>
     */
    public function all(): array
    {
        return $this->gaps;
    }

    /**
     * @return list<Gap>
     */
    public function blocking(): array
    {
        return array_values(array_filter($this->gaps, fn (Gap $gap) => $gap->severity === Severity::Blocks));
    }

    /**
     * Gaps that bring the guide out on their own: those that block, and
     * images the page looks like it needs (Severity::Prompt).
     *
     * @return list<Gap>
     */
    public function prompting(): array
    {
        return array_values(array_filter($this->gaps, fn (Gap $gap) => $gap->prompts()));
    }

    /**
     * Gaps that block, prompt or that the CMS requires: what the pill counts.
     *
     * @return list<Gap>
     */
    public function counted(): array
    {
        return array_values(array_filter($this->gaps, fn (Gap $gap) => $gap->counts()));
    }

    /** Blocks + Prompt + Required: what the pill shows. */
    public function count(): int
    {
        return count($this->counted());
    }

    public function suggestions(): int
    {
        return count($this->gaps) - $this->count();
    }

    public function isEmpty(): bool
    {
        return $this->gaps === [];
    }

    /**
     * @return list<Gap>
     */
    public function ofKind(GapKind $kind): array
    {
        return array_values(array_filter($this->gaps, fn (Gap $gap) => $gap->kind === $kind));
    }

    public function find(string $id): ?Gap
    {
        foreach ($this->gaps as $gap) {
            if ($gap->id === $id) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * For the front end, as JSON.
     *
     * - `prompting`: how many bring the guide out on their own (prompting()).
     *
     * @return array{count: int, blocking: int, prompting: int, suggestions: int, gaps: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count(),
            'blocking' => count($this->blocking()),
            'prompting' => count($this->prompting()),
            'suggestions' => $this->suggestions(),
            'gaps' => array_map(fn (Gap $gap) => $gap->toArray(), $this->gaps),
        ];
    }
}
