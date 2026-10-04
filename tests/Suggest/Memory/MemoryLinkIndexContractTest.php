<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest\Memory;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\LinkIndex;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\MemoryEntryIndex;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkIndexContractTest;

final class MemoryLinkIndexContractTest extends LinkIndexContractTest
{
    private ?MemoryEntryIndex $index = null;

    /** @var array<string, bool> */
    private array $revisit = [];

    protected function linkIndex(): LinkIndex
    {
        return $this->index();
    }

    protected function entryIndex(): EntryIndex
    {
        return $this->index();
    }

    protected function linkEntries(): array
    {
        return [
            'design' => new EntryRef('services', 'design', 'default'),
            'about' => new EntryRef('pages', 'about', 'default'),
            'contact' => new EntryRef('pages', 'contact', 'default'),
            'draft' => new EntryRef('pages', 'draft', 'default'),
            'scheduled' => new EntryRef('pages', 'open-day', 'default'),
            'noindex' => new EntryRef('pages', 'offer', 'default'),
            'search' => new EntryRef('pages', 'search', 'default'),
            'home' => new EntryRef('pages', 'home', 'default'),
            'other' => new EntryRef('pages', 'about', 'cy'),
        ];
    }

    protected function draftGroup(): string
    {
        return 'services';
    }

    protected function scheduledFrom(): DateTimeImmutable
    {
        return new DateTimeImmutable('+10 days');
    }

    protected function retitle(EntryRef $entry, string $title): void
    {
        $row = $this->index()->row($entry);
        $this->assertNotNull($row);
        $this->index()->put(IndexRow::make($row->entry, $row->scope, $title, $row->url, $row->summary, $row->type, $row->kind, link: $row->link, locale: 'en'));
    }

    protected function deleteEntry(EntryRef $entry): void
    {
        $this->index()->forget($entry);
    }

    protected function hasRevisitRow(EntryRef $entry): bool
    {
        return $this->revisit[$entry->key()] ?? false;
    }

    private function index(): MemoryEntryIndex
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $e = $this->linkEntries();
        $link = fn (EntryRef $ref, string $title, string $url, string $summary = '', bool $key = false, bool $published = true, ?string $liveFrom = null, bool $noindex = false) => IndexRow::make($ref, IndexScope::Link, $title, $url, $summary, 'Pages', liveFrom: $liveFrom, noindex: $noindex, key: $key, link: 'entry::'.$ref->id, published: $published, locale: 'en');

        $this->index = (new MemoryEntryIndex)
            ->add($e['design'], 'Garden design', [self::ABOUT.' '.self::DRAFT], '/services/garden-design', 'A full design for your garden.', 'entry::design', 'Services', 'en')
            ->put($link($e['about'], 'About our garden design studio', '/about', self::ABOUT))
            ->put($link($e['contact'], 'Contact us', '/contact', 'Book a garden design consultation or ask us a question.', key: true))
            ->put($link($e['draft'], 'Garden design draft notes', '/draft', published: false))
            ->put($link($e['scheduled'], 'Garden design open day', '/open-day', liveFrom: $this->scheduledFrom()->format(DATE_ATOM)))
            ->put($link($e['noindex'], 'Garden design offer', '/offer', noindex: true))
            ->put($link($e['search'], 'Search garden design', '/search'))
            ->put($link($e['home'], 'Garden design studio', '/'))
            ->put($link($e['other'], 'About our garden design studio', '/cy/about', self::ABOUT));
        $this->revisit[$e['design']->key()] = true;

        return $this->index;
    }
}
