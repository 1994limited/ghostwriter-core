<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * What every EntrySource must do. The addon saves entries through its CMS
 * and says which they are:
 *
 * - `old`: published, last saved before `between`;
 * - `recent`: published, saved after `between`;
 * - `draft`: not published, on the same site;
 * - `other`: published, on another site (or tenant);
 * - `site`: the first site.
 */
trait EntrySourceContract
{
    abstract protected function entrySource(): EntrySource;

    /**
     * @return array{old: EntryRef, recent: EntryRef, draft: EntryRef, other: EntryRef, site: int|string, between: DateTimeImmutable}
     */
    abstract protected function sourceEntries(): array;

    /**
     * @param  iterable<EntrySnapshot|EntryRef>  $items
     * @return list<string>
     */
    private static function keys(iterable $items): array
    {
        $keys = [];

        foreach ($items as $item) {
            $keys[] = ($item instanceof EntrySnapshot ? $item->ref : $item)->key();
        }

        return $keys;
    }

    public function test_all_is_published_entries_of_one_site(): void
    {
        $entries = $this->sourceEntries();
        $keys = self::keys($this->entrySource()->all($entries['site']));

        $this->assertContains($entries['old']->key(), $keys);
        $this->assertContains($entries['recent']->key(), $keys);
        $this->assertNotContains($entries['draft']->key(), $keys);
        $this->assertNotContains($entries['other']->key(), $keys);
        $this->assertContains($entries['other']->key(), self::keys($this->entrySource()->all()), 'Every site with null.');
    }

    public function test_chunks_give_the_same_entries(): void
    {
        $entries = $this->sourceEntries();

        $this->assertEqualsCanonicalizing(self::keys($this->entrySource()->all($entries['site'])), self::keys($this->entrySource()->all($entries['site'], 1)));
    }

    public function test_updated_since_is_the_fast_path(): void
    {
        $entries = $this->sourceEntries();
        $keys = self::keys($this->entrySource()->updatedSince($entries['between'], $entries['site']));

        $this->assertContains($entries['recent']->key(), $keys);
        $this->assertNotContains($entries['old']->key(), $keys);
    }

    public function test_find_gives_a_snapshot_the_checks_can_read(): void
    {
        $entries = $this->sourceEntries();
        $snapshot = $this->entrySource()->find($entries['old']);

        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->published);
        $this->assertSame($entries['old']->key(), $snapshot->ref->key());
        $this->assertNotSame('', $snapshot->title);
        $this->assertNotNull($snapshot->context->updatedAt);
        $this->assertLessThan($entries['between'], $snapshot->context->updatedAt);
        $this->assertNotEmpty($snapshot->context->texts(), 'Its text is readable.');
    }

    public function test_find_says_when_an_entry_is_unpublished_or_gone(): void
    {
        $entries = $this->sourceEntries();

        $this->assertFalse($this->entrySource()->find($entries['draft'])?->published ?? false);
        $this->assertNull($this->entrySource()->find(new EntryRef($entries['old']->group, 'no-such-entry-999', $entries['site'])));
    }
}
