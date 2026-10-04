<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkGuard;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use PHPUnit\Framework\TestCase;

/**
 * LinkGuard (SEO layer §5.2): every link after a writer turn is one the
 * previous draft had, one the SEO pass added, a marker, or an outside
 * address from the brief or the conversation; anything else becomes a
 * `#gw-link:` marker, and a link the editor removed stays removed.
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

    public function test_hrefs_finds_every_link_but_images(): void
    {
        $this->assertSame(['/a', 'statamic://entry::b'], LinkGuard::hrefs(['x' => '[a](/a) ![i](/i.jpg)', 'y' => ['[b](statamic://entry::b)', '[a](/a)']]));
    }
}
