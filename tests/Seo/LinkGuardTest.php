<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkGuard;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\MemoryEntryIndex;
use PHPUnit\Framework\TestCase;

/**
 * LinkGuard (SEO layer §5.2): every link after a writer turn is one the
 * previous draft had, one the SEO pass added, a marker, an outside
 * address from the brief or the conversation, or a link to a real page of
 * the site (decision 22); anything else becomes a `#gw-link:` marker, and
 * a link the editor removed stays removed.
 */
final class LinkGuardTest extends TestCase
{
    public function test_a_made_up_internal_address_becomes_a_marker(): void
    {
        [$data, $changes] = (new LinkGuard)->guard(['body' => 'Read [our winter guide](/journal/winter-guide) or [call us](tel:0191000) today.'], null, []);

        $this->assertSame('Read [our winter guide](#gw-link:our-winter-guide) or [call us](#gw-link:call-us) today.', $data['body']);
        $this->assertSame(['/journal/winter-guide', 'tel:0191000'], array_column($changes, 'href'));
    }

    public function test_kept_links_markers_and_outside_addresses_from_the_brief_pass(): void
    {
        $previous = ['body' => 'See [plans](statamic://entry::plans) and [the RHS](https://www.rhs.org.uk/pruning).'];
        $state = SeoState::fromArray(['links' => [['unit' => 'u3', 'words' => 'contact', 'href' => '{entry:12@1:url||/contact}', 'title' => 'Contact us', 'type' => 'Pages', 'url' => '/contact', 'why' => '']]]);
        $draft = [
            'body' => 'See [plans](statamic://entry::plans), [the RHS](https://www.rhs.org.uk/pruning), [contact us]({entry:12@1:url||/contact}), [book](#gw-link:booking-page), [email](mailto:hello@northfold.test) and [the council](https://www.northumberland.gov.uk/).',
            'list' => ['[Top](#top)', '![A photo](/assets/photo.jpg)'],
        ];

        [$data, $changes] = (new LinkGuard)->guard($draft, $previous, ['Write to hello@northfold.test', 'Bins: https://www.northumberland.gov.uk'], $state);

        $this->assertSame([], $changes);
        $this->assertSame($draft, $data);
    }

    public function test_the_same_page_written_another_way_is_the_same_link(): void
    {
        [$data, $changes] = (new LinkGuard)->guard(['body' => '[Plans](entry::plans)'], ['body' => '[Plans](statamic://entry::plans)'], []);

        $this->assertSame([], $changes);
        $this->assertSame('[Plans](entry::plans)', $data['body']);
    }

    public function test_a_removed_link_put_back_by_the_writer_loses_its_link_and_keeps_its_words(): void
    {
        $state = SeoState::fromArray(['removed' => ['statamic://entry::contact']]);
        [$data] = (new LinkGuard)->guard(['body' => 'Do [tell us about your garden](statamic://entry::contact) soon.'], ['body' => 'Do tell us about your garden soon.'], [], $state);

        $this->assertSame('Do tell us about your garden soon.', $data['body']);
    }

    private static function site(?EntryRef $except = null): LinkContext
    {
        $index = (new MemoryEntryIndex)
            ->put(IndexRow::make(new EntryRef('journal', 'october', 'default'), IndexScope::Full, 'What to do in the garden in October', '/journal/october', link: 'entry::october', locale: 'en'))
            ->put(IndexRow::make(new EntryRef('pages', 'contact', 'default'), IndexScope::Link, 'Contact us', '/contact', key: true, link: 'entry::contact', locale: 'en'))
            ->put(IndexRow::make(new EntryRef('pages', 'offer', 'default'), IndexScope::Link, 'Winter offer', '/offer', noindex: true, link: 'entry::offer', locale: 'en'))
            ->put(IndexRow::make(new EntryRef('pages', 'search', 'default'), IndexScope::Link, 'Search', '/search', link: 'entry::search', locale: 'en'))
            ->put(IndexRow::make(new EntryRef('journal', 'spring', 'default'), IndexScope::Full, 'Spring jobs', '/journal/spring', liveFrom: '2999-03-01', link: 'entry::spring', locale: 'en'))
            ->put(IndexRow::make(new EntryRef('journal', 'october', 'cy'), IndexScope::Full, 'Hydref', '/cy/journal/october', link: 'entry::october-cy', locale: 'cy'));

        return new LinkContext($index, new StatamicLinks, 'journal', 'default', $except);
    }

    public function test_a_writer_link_to_a_real_page_of_the_site_is_kept_as_the_dialect_writes_it(): void
    {
        $draft = ['body' => 'See [October jobs](entry::october), [what to do in October](/journal/october/) and [contact us](statamic://entry::contact).'];

        [$data, $changes, $kept] = (new LinkGuard)->guard($draft, null, [], new SeoState, self::site());

        $this->assertSame('See [October jobs](statamic://entry::october), [what to do in October](statamic://entry::october) and [contact us](statamic://entry::contact).', $data['body']);
        $this->assertSame([], $changes);
        $this->assertSame(['What to do in the garden in October', 'What to do in the garden in October', 'Contact us'], array_column($kept, 'title'));
    }

    public function test_a_writer_link_to_a_page_that_cant_be_linked_to_is_a_marker(): void
    {
        $draft = ['body' => '[Gone](statamic://entry::gone), [the offer](statamic://entry::offer), [search](/search), [spring](statamic://entry::spring), [Welsh](statamic://entry::october-cy), [their contact page](https://www.rhs.org.uk/contact), [this page](statamic://entry::october).'];

        [$data, $changes, $kept] = (new LinkGuard)->guard($draft, null, [], new SeoState, self::site(new EntryRef('journal', 'october', 'default')));

        $this->assertSame('[Gone](#gw-link:gone), [the offer](#gw-link:the-offer), [search](#gw-link:search), [spring](#gw-link:spring), [Welsh](#gw-link:welsh), [their contact page](#gw-link:their-contact-page), [this page](#gw-link:this-page).', $data['body'], 'No such page, noindex, a utility page, not live yet, another site, another site\'s address, the page itself.');
        $this->assertCount(7, $changes);
        $this->assertSame([], $kept);
    }

    public function test_without_a_link_lookup_or_once_removed_a_real_page_is_not_kept(): void
    {
        [$data] = (new LinkGuard)->guard(['body' => '[contact us](statamic://entry::contact)'], null, []);
        $this->assertSame('[contact us](#gw-link:contact-us)', $data['body']);

        [$data] = (new LinkGuard)->guard(['body' => 'Do [contact us](statamic://entry::contact).'], null, [], SeoState::fromArray(['removed' => ['statamic://entry::contact']]), self::site());
        $this->assertSame('Do contact us.', $data['body']);
    }

    public function test_hrefs_finds_every_link_but_images(): void
    {
        $this->assertSame(['/a', 'statamic://entry::b'], LinkGuard::hrefs(['x' => '[a](/a) ![i](/i.jpg)', 'y' => ['[b](statamic://entry::b)', '[a](/a)']]));
    }
}
