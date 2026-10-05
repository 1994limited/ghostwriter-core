<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryLinkTargets;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Priority;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitReason;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitScanner;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewPrompt;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SiteDigest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReply;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionValidator;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\MemoryEntryIndex;
use PHPUnit\Framework\TestCase;

/**
 * The SEO category on existing pages (SEO layer §13): a long heading, an
 * empty SEO description and a long page with no link to the site are
 * candidates for the reviewer, found for nothing; Content to revisit
 * weighs them lightly.
 */
final class SeoCandidatesTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private const HEADING = 'What we do in a walled garden in late winter, before the first warm weekend arrives';

    private static function body(string $extra = ''): string
    {
        $paragraph = 'We cut back the grasses, lift and divide the perennials, and mulch the borders so the soil is ready for spring planting in the walled garden. ';

        return '## '.self::HEADING."\n\n".trim(str_repeat($paragraph, 12)).$extra."\n\n## Planting plans\n\n".trim(str_repeat($paragraph, 2));
    }

    private static function index(): MemoryEntryIndex
    {
        return (new MemoryEntryIndex)
            ->put(IndexRow::make(new EntryRef('pages', 'contact', 'default'), IndexScope::Link, 'Contact us', '/contact', 'Tell us about your garden and we will come and see it.', 'Pages', key: true, link: 'entry::contact'))
            ->put(IndexRow::make(new EntryRef('services', 'plans', 'default'), IndexScope::Full, 'Planting plans', '/services/planting-plans', 'Planting plans for borders and walled gardens.', 'Services', link: 'entry::plans'));
    }

    private static function context(string $body, string $description = '', ?MemoryEntryIndex $index = null): CheckContext
    {
        $schema = new Schema([new Field('title', Kind::Text, 'Title'), new Field('body', Kind::RichText, 'Body', type: 'markdown'), new Field('meta_description', Kind::LongText, 'Meta description')]);

        return new CheckContext(
            gaps: new GapContext(
                schema: $schema,
                entry: new EntryData(['title' => 'Winter care', 'body' => $body, 'meta_description' => $description], group: 'services'),
                richText: new MarkdownDialect,
                targets: new MemoryLinkTargets(['contact' => ['title' => 'Contact us', 'url' => '/contact'], 'plans' => ['title' => 'Planting plans', 'url' => '/services/planting-plans']]),
                seo: new PlainSeoFields,
            ),
            now: new DateTimeImmutable(self::NOW),
            updatedAt: new DateTimeImmutable('2026-09-01'),
            index: $index ?? self::index(),
            entry: new EntryRef('services', 'winter-care', 'default'),
        );
    }

    /**
     * @return array<string, Finding>
     */
    private static function byKind(CheckContext $context): array
    {
        $out = [];

        foreach (Findings::standard()->find($context) as $finding) {
            $out[$finding->kind] = $finding;
        }

        return $out;
    }

    public function test_a_long_heading_an_empty_description_and_no_links_are_candidates(): void
    {
        $found = self::byKind(self::context(self::body()));

        $this->assertSame(Category::Seo, $found['heading-long']->category);
        $this->assertSame(Needs::Words, $found['heading-long']->needs);
        $this->assertSame(self::HEADING, $found['heading-long']->anchor->quote?->exact);

        $this->assertSame(Category::Seo, $found['seo-missing']->category);
        $this->assertTrue($found['seo-missing']->meta['empty']);
        $this->assertArrayNotHasKey('seo-empty', $found, 'Said once.');

        $this->assertSame(Category::Link, $found['few-links']->category);
        $this->assertFalse($found['few-links']->alone, 'Nothing to accept until the reviewer proposes links.');
        $this->assertNull($found['few-links']->toSuggestion());
    }

    public function test_a_description_that_fits_and_a_page_with_a_link_are_not(): void
    {
        $fits = 'Winter visits to cut back, divide and mulch established gardens across Northumberland, Durham and the Tyne Valley, from November.';
        $found = self::byKind(self::context(self::body(' See [our planting plans](entry::plans).'), $fits));

        $this->assertArrayNotHasKey('seo-missing', $found);
        $this->assertArrayNotHasKey('few-links', $found);
        $this->assertArrayHasKey('heading-long', $found);
    }

    public function test_the_reviewer_is_shown_pages_to_link_to_and_how_to_answer(): void
    {
        $context = self::context(self::body());
        $findings = Findings::standard()->find($context);
        $digest = SiteDigest::build($context, $findings);
        $titles = array_map(fn ($entry) => $entry->title, $digest->all());

        $this->assertContains('Contact us', $titles, 'Link-only pages are offered to a page with no links.');
        $this->assertContains('Planting plans', $titles);

        $input = new ReviewInput($context, ReviewCase::writer(), $findings, $digest);
        $prompt = ReviewPrompt::render($input, $input->batches()[0]);

        $this->assertStringContainsString('If kept: write the heading shorter, under 60 characters', $prompt);
        $this->assertStringContainsString('If kept: no words for this one; add up to 3 link suggestions of your own instead', $prompt);
        $this->assertStringContainsString('If kept: write the whole description, 120 to 155 characters', $prompt);

        $fake = new FakeProvider;
        $fake->respond('reviewer', '{"suggestions": []}');
        ReviewCase::studio($fake)->suggestEdits($input);
        $this->assertStringContainsString('## Search: headings', $fake->requests()[0]->instructions);
        $this->assertStringContainsString('## Search: links', $fake->requests()[0]->instructions);
    }

    public function test_kept_no_links_candidate_adds_nothing_but_the_links_proposed_are_suggestions(): void
    {
        $context = self::context(self::body());
        $findings = Findings::standard()->find($context);
        $input = new ReviewInput($context, ReviewCase::writer(), $findings, SiteDigest::build($context, $findings));
        $numbers = array_flip(array_map(fn (Finding $finding) => $finding->kind, $input->numbered()));
        $contact = array_search('Contact us', array_map(fn ($entry) => $entry->title, $input->digest->all()), true);
        $units = $input->batches()[0]->units;
        $body = null;

        foreach ($units as $unit) {
            if (str_contains($unit->markdown, 'mulch the borders')) {
                $body = $unit->id;

                break;
            }
        }

        $reply = new SuggestionReply([
            ['batch' => 0, 'item' => ['finding' => $numbers['few-links'], 'notes' => 'Contact fits.']],
            ['batch' => 0, 'item' => ['category' => 'link', 'unit' => $body, 'quote' => 'mulch the borders', 'occurrence' => 0, 'reason' => 'Readers may want us to do it.', 'source' => ['kind' => 'site-entry', 'entry' => $contact], 'link' => ['entry' => $contact]]],
            ['batch' => 0, 'item' => ['finding' => $numbers['heading-long'], 'reason' => 'Too long to scan.', 'source' => ['kind' => 'finding'], 'replacement' => 'What we do in a walled garden in late winter']],
            ['batch' => 0, 'item' => ['finding' => $numbers['seo-missing'], 'drop' => 'Not now.']],
        ]);
        $review = (new SuggestionValidator)->validate($reply, $input);
        $kinds = array_map(fn ($suggestion) => [$suggestion->category->value, $suggestion->replacement, $suggestion->link?->title], $review->suggestions);

        $this->assertContains(['seo', 'What we do in a walled garden in late winter', null], $kinds);
        $this->assertContains(['link', null, 'Contact us'], $kinds);
        $this->assertCount(2, $review->suggestions, 'The kept "no links" candidate is answered by the link, not shown itself.');
    }

    public function test_content_to_revisit_weighs_seo_lightly_and_together(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $scan = fn (string $body) => (new RevisitScanner)->scan(new EntrySnapshot(new EntryRef('services', 'winter-care', 'default'), 'Winter care', null, self::context($body)), $now);
        $row = $scan("# Winter care\n\n".self::body());
        $kinds = array_map(fn (RevisitReason $reason) => $reason->kind, $row->reasons);

        $this->assertContains(ReasonKind::SeoMissing, $kinds);
        $this->assertContains(ReasonKind::FewLinks, $kinds);
        $this->assertContains(ReasonKind::HeadingLevels, $kinds, 'A body H1 under the template\'s.');
        $this->assertNotContains(ReasonKind::EmptyField, $kinds);
        $this->assertSame(['No SEO description', 'No internal links', 'Heading levels'], array_values(array_map(fn (RevisitReason $reason) => $reason->message()->english(), array_filter($row->reasons, fn (RevisitReason $reason) => $reason->kind->isSeo()))));

        $broken = $scan(self::body(' See [our old page](entry::gone).'));
        $this->assertGreaterThan($row->score, (new Priority)->score([new RevisitReason(ReasonKind::BrokenLink)], null, $now, new AgePolicy, 'services'), 'A broken link outranks no description and no links.');
        $this->assertSame(Priority::SEO_CAP, (new Priority)->score([
            new RevisitReason(ReasonKind::SeoMissing), new RevisitReason(ReasonKind::FewLinks), new RevisitReason(ReasonKind::HeadingLevels),
            new RevisitReason(ReasonKind::SeoLength, 2), new RevisitReason(ReasonKind::Competing), new RevisitReason(ReasonKind::Readability, 2),
        ], null, $now, new AgePolicy, 'services'), 'All SEO reasons together count for at most 25.');
        $this->assertSame(14, (new Priority)->score([new RevisitReason(ReasonKind::SeoMissing), new RevisitReason(ReasonKind::FewLinks), new RevisitReason(ReasonKind::HeadingLevels)], null, $now, new AgePolicy, 'services'));
        $this->assertNotNull($broken);
    }
}
