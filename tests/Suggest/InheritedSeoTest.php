<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\ReasonKind;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitReason;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitScanner;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use PHPUnit\Framework\TestCase;

/**
 * Suggest edits and Content to revisit with an SEO description the page
 * inherits (decision 11): one that fits is left alone, an empty or
 * over-long one is still found.
 */
final class InheritedSeoTest extends TestCase
{
    private const EXCERPT = 'A small back yard, a downpipe that flooded the kitchen step every winter, and a planted hollow that now takes all of it.';

    public function test_an_empty_description_that_inherits_text_that_fits_is_not_missing(): void
    {
        $report = Findings::standard()->report($this->context(self::EXCERPT));

        $this->assertSame([], array_values(array_filter($report->findings, fn (Finding $finding) => $finding->kind === 'seo-empty')));
        $this->assertSame(0, $report->emptyFields(), 'Not an empty field in the revisit list either.');
    }

    public function test_an_empty_description_whose_source_is_empty_is_missing(): void
    {
        $report = Findings::standard()->report($this->context(''));
        $empty = array_values(array_filter($report->findings, fn (Finding $finding) => $finding->kind === 'seo-empty'));

        $this->assertCount(1, $empty);
        $this->assertSame('field', $empty[0]->meta['source']);
        $this->assertSame(1, $report->emptyFields());
    }

    public function test_an_empty_custom_description_is_missing(): void
    {
        $report = Findings::standard()->report($this->context(null));

        $this->assertCount(1, array_filter($report->findings, fn (Finding $finding) => $finding->kind === 'seo-empty'));
    }

    public function test_an_inherited_description_too_long_is_found_with_its_source(): void
    {
        $long = Findings::standard()->find($this->context(str_repeat('A planted hollow. ', 12)));
        $seo = array_values(array_filter($long, fn (Finding $finding) => $finding->kind === 'seo-length'));

        $this->assertCount(1, $seo);
        $this->assertSame('field', $seo[0]->meta['source']);
        $this->assertSame('Excerpt', $seo[0]->meta['inheritsFrom']);
    }

    public function test_content_to_revisit_counts_no_empty_field_for_an_inherited_description(): void
    {
        $now = new DateTimeImmutable('2026-10-04 10:00:00');
        $reasons = fn (?string $excerpt) => array_map(fn (RevisitReason $reason) => $reason->kind, (new RevisitScanner)->scan(new EntrySnapshot(new EntryRef('journal', 'rain-garden', 'default'), 'A rain garden', null, $this->context($excerpt, $now)), $now)->reasons);

        $this->assertNotContains(ReasonKind::EmptyField, $reasons(self::EXCERPT));
        $this->assertNotContains(ReasonKind::SeoMissing, $reasons(self::EXCERPT));
        $this->assertContains(ReasonKind::SeoMissing, $reasons(''), 'Inherited from an empty field: the page prints no description.');
        $this->assertNotContains(ReasonKind::EmptyField, $reasons(''), 'Said once, as its own reason.');
    }

    /**
     * A journal post whose plain `meta_description` is empty, with an SEO
     * addon that fills it from the excerpt while it is (as Filament's
     * `->ghostwriterSeo('description', fallback: 'excerpt')` does); null
     * for no fallback.
     */
    private function context(?string $excerpt, ?DateTimeImmutable $now = null): CheckContext
    {
        $schema = new Schema([
            new Field('title', Kind::Text, 'Title'),
            new Field('excerpt', Kind::LongText, 'Excerpt'),
            new Field('meta_description', Kind::LongText, 'Meta description'),
        ]);
        $seo = new class($excerpt !== null) implements SeoFields
        {
            public function __construct(private bool $fallback) {}

            public function in(Schema $schema, EntryData $entry): array
            {
                $own = (string) $entry->get('meta_description');
                $path = FieldPath::of('meta_description');

                return [$own === '' && $this->fallback
                    ? new SeoField($path, SeoField::DESCRIPTION, 'Meta description', 160, (string) $entry->get('excerpt'), true, 'Excerpt')
                    : new SeoField($path, SeoField::DESCRIPTION, 'Meta description', 160, $own, source: SeoSource::Custom)];
            }

            public function noindex(Schema $schema, EntryData $entry): ?bool
            {
                return null;
            }

            public function titleFormat(Schema $schema, EntryData $entry): ?TitleFormat
            {
                return null;
            }
        };

        return new CheckContext(
            gaps: new GapContext(
                schema: $schema,
                entry: new EntryData(['title' => 'A rain garden', 'excerpt' => (string) $excerpt, 'meta_description' => ''], group: 'journal'),
                pattern: new Pattern(filled: ['meta_description' => 0.9]),
                seo: $seo,
            ),
            now: $now ?? new DateTimeImmutable('2026-10-04 10:00:00'),
        );
    }
}
