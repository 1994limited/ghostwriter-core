<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTargets;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * Link targets from a list of entries, as Statamic stores links to them
 * (`entry::id`), for tests and the demo. A value naming an entry that
 * isn't in the list is broken; anything else (an outside address) can't
 * be told.
 */
final class MemoryLinkTargets implements LinkTargets
{
    /** @var array<int, string> The hints searched for, in order. */
    public array $searched = [];

    /**
     * @param  array<string, array{title: string, slug?: string, url?: string}>  $entries  By ID.
     */
    public function __construct(private readonly array $entries = []) {}

    public function exists(mixed $target, Field $field): ?bool
    {
        $ids = [];

        foreach (is_array($target) ? $target : [$target] as $item) {
            if (is_string($item) && preg_match('/^(?:statamic:\/\/)?entry::(.+)$/', $item, $match) === 1) {
                $ids[] = $match[1];
            }
        }

        if ($ids === []) {
            return null;
        }

        foreach ($ids as $id) {
            if (! isset($this->entries[$id])) {
                return false;
            }
        }

        return true;
    }

    public function search(string $hint, int $limit = 3): array
    {
        $this->searched[] = $hint;
        $words = array_filter(explode('-', Slug::make($hint)));
        $scored = [];

        foreach ($this->entries as $id => $entry) {
            $haystack = Slug::make($entry['title']).'-'.($entry['slug'] ?? '');
            $score = count(array_filter($words, fn (string $word) => str_contains($haystack, $word)));

            if ($score > 0) {
                $scored[] = [$score, new LinkTarget("entry::{$id}", $entry['title'], $entry['url'] ?? null)];
            }
        }

        usort($scored, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_slice(array_map(fn (array $pair) => $pair[1], $scored), 0, $limit);
    }
}
