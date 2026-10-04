<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\RecordingSleeper;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ExternalLinkCheck;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkStatus;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitIndex;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitOptions;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitScanner;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing\InMemoryRevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Testing\MemoryEntrySource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use PHPUnit\Framework\TestCase;

/** The opt-in weekly check of links to other sites. */
final class ExternalLinkCheckTest extends TestCase
{
    /** @var list<string> */
    private array $probed = [];

    private InMemoryRevisitStore $store;

    private RecordingSleeper $sleeper;

    private ExternalLinkCheck $check;

    private const BODY = 'Read [the RHS guide](https://www.rhs.org.uk/gone), [the forecast](https://metoffice.gov.uk/weather) and [our own page](https://northfold.co.uk/about). Also [more RHS](https://rhs.org.uk/other).';

    protected function setUp(): void
    {
        $this->store = new InMemoryRevisitStore;
        $this->sleeper = new RecordingSleeper;
        $scanner = new RevisitScanner(ownHosts: ['www.northfold.co.uk']);
        $probed = &$this->probed;
        $probe = new class($probed) implements LinkProbe
        {
            /** @param list<string> $probed */
            public function __construct(private array &$probed) {}

            public function probe(string $url, int $timeout): LinkResult
            {
                $this->probed[] = $url;

                return new LinkResult($url, str_ends_with($url, '/gone') ? LinkStatus::Broken : LinkStatus::Ok, str_ends_with($url, '/gone') ? 404 : 200, '2026-10-04T10:00:00+00:00');
            }
        };
        $this->check = new ExternalLinkCheck($probe, $scanner, $this->sleeper);

        $source = (new MemoryEntrySource)
            ->put(Fixtures::snapshot(new EntryRef('pages', 'services', 'default'), 'Services', '2026-09-01', self::BODY))
            ->put(Fixtures::snapshot(new EntryRef('pages', 'about', 'default'), 'About', '2026-09-01', 'See [the RHS guide](https://www.rhs.org.uk/gone).'));
        (new RevisitIndex($scanner, $this->store))->refresh($source, new DateTimeImmutable(Fixtures::NOW));
    }

    public function test_off_by_default_it_makes_no_request(): void
    {
        $this->assertFalse((new RevisitOptions)->externalLinks);
        $this->assertSame(0, $this->check->run($this->store, new RevisitOptions, new DateTimeImmutable(Fixtures::NOW)));
        $this->assertSame([], $this->probed);
    }

    public function test_each_address_once_politely_and_never_the_sites_own(): void
    {
        $this->assertSame(3, $this->check->run($this->store, new RevisitOptions(externalLinks: true), new DateTimeImmutable(Fixtures::NOW)));

        $this->assertEqualsCanonicalizing(['https://www.rhs.org.uk/gone', 'https://metoffice.gov.uk/weather', 'https://rhs.org.uk/other'], $this->probed);
        $this->assertSame(['https://www.rhs.org.uk/gone', 'https://metoffice.gov.uk/weather', 'https://rhs.org.uk/other'], $this->probed, 'Round-robin across hosts.');
        $this->assertCount(1, $this->sleeper->waits, 'One wait: the second request to rhs.org.uk.');
        $this->assertLessThanOrEqual(ExternalLinkCheck::PER_HOST, $this->sleeper->waits[0]);

        $this->probed = [];
        $this->assertSame(0, $this->check->run($this->store, new RevisitOptions(externalLinks: true), new DateTimeImmutable('2026-10-08')), 'Not again within the week.');
    }

    public function test_a_link_shows_only_after_two_failed_weeks_then_as_a_link_finding(): void
    {
        $on = new RevisitOptions(externalLinks: true);
        $this->check->run($this->store, $on, new DateTimeImmutable(Fixtures::NOW));
        $services = new EntryRef('pages', 'services', 'default');

        $this->assertFalse($this->store->get($services)?->has(ReasonKind::ExternalLink), 'Once is not enough.');

        $this->check->run($this->store, $on, new DateTimeImmutable('2026-10-12'));
        $row = $this->store->get($services);

        $this->assertTrue($row?->has(ReasonKind::ExternalLink));
        $chips = array_map(fn ($reason) => $reason->message()->english(), $row->reasons);
        $this->assertContains('1 link to another site failed', $chips);
        $this->assertTrue($this->store->get(new EntryRef('pages', 'about', 'default'))?->has(ReasonKind::ExternalLink));

        $findings = array_values(array_filter(
            Findings::standard()->find(Fixtures::snapshot($services, 'Services', '2026-09-01', self::BODY, external: $row->external)->context),
            fn (Finding $finding) => $finding->kind === 'external-link',
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(Category::Link, $findings[0]->category);
        $this->assertSame('the RHS guide', $findings[0]->anchor->quote?->exact);
        $this->assertSame('The “the RHS guide” link didn\'t work when it was last checked (404).', $findings[0]->message->english());
    }

    public function test_a_link_that_works_again_is_cleared(): void
    {
        $broken = new LinkResult('https://a.example/x', LinkStatus::Broken, 404, '2026-10-01', 2);

        $this->assertTrue($broken->isBroken());
        $this->assertFalse((new LinkResult('https://a.example/x', LinkStatus::Ok, 200, '2026-10-08'))->after($broken)->isBroken());
        $this->assertTrue((new LinkResult('https://a.example/x', LinkStatus::Unknown, null, '2026-10-08'))->after($broken)->isBroken(), 'A timeout changes nothing.');
    }
}
