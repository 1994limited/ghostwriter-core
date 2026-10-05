<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\SeoMissing;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlainSeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Severity;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use PHPUnit\Framework\TestCase;

/**
 * "Add a description for search" (SEO layer §12, `seo-missing`): an empty
 * or too short SEO description, offered the draft's own where the session
 * has one. A suggestion that never blocks.
 */
final class SeoMissingTest extends TestCase
{
    private const DRAFT = 'Monthly winter visits to cut back, divide and mulch established gardens across Northumberland, Durham and the Tyne Valley, November to February.';

    private const BODY = 'We visit established gardens once a month from November to February, to cut back, divide and mulch the borders.';

    private static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title'),
            new Field('body', Kind::LongText, 'Body'),
            new Field('meta_description', Kind::LongText, 'Meta description', meta: ['character_limit' => 160]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<Gap>
     */
    private static function gaps(array $values, ?SessionGaps $session = null, ?SeoFields $seo = null): array
    {
        $context = new GapContext(self::schema(), new EntryData(['title' => 'Winter care', 'body' => self::BODY] + $values), seo: $seo ?? new PlainSeoFields, session: $session ?? new SessionGaps);

        return array_values([...(new SeoMissing)->detect($context)]);
    }

    public function test_an_empty_description_offers_the_drafts(): void
    {
        $gaps = self::gaps(['meta_description' => ''], new SessionGaps(meta: ['description' => self::DRAFT]));

        $this->assertCount(1, $gaps);
        $gap = $gaps[0];
        $this->assertSame(GapKind::SeoMissing, $gap->kind);
        $this->assertSame(Severity::Suggestion, $gap->severity);
        $this->assertFalse($gap->counts(), 'Never in the pill.');
        $this->assertSame('meta_description', $gap->path->toString());
        $this->assertSame([FixAction::UseText, FixAction::Focus], array_map(fn ($fix) => $fix->action, $gap->fixes));
        $this->assertSame(self::DRAFT, $gap->fixes[0]->value);
        $this->assertTrue($gap->fixes[0]->primary);
        $this->assertSame('The SEO description is empty, so search engines will pick their own. Here\'s the one from the draft: “'.self::DRAFT.'”', $gap->message()->english());
        $this->assertSame('gaps.step.seo-missing', $gap->meta['step']);
    }

    public function test_without_the_drafts_only_ill_write_it(): void
    {
        $gaps = self::gaps(['meta_description' => '']);

        $this->assertCount(1, $gaps);
        $this->assertSame([FixAction::Focus], array_map(fn ($fix) => $fix->action, $gaps[0]->fixes));
        $this->assertTrue($gaps[0]->fixes[0]->primary);
        $this->assertSame('The SEO description is empty, so search engines will pick their own.', $gaps[0]->message()->english());
    }

    public function test_too_short_but_not_one_that_fits_or_is_too_long(): void
    {
        $short = self::gaps(['meta_description' => 'Winter visits.'], new SessionGaps(meta: ['description' => self::DRAFT]));
        $this->assertCount(1, $short);
        $this->assertStringStartsWith('The SEO description is only 14 characters.', $short[0]->message()->english());

        $this->assertSame([], self::gaps(['meta_description' => self::DRAFT]));
        $this->assertSame([], self::gaps(['meta_description' => str_repeat('Winter visits. ', 12)]), 'Too long is SeoLength\'s.');
    }

    public function test_an_inherited_description_from_an_empty_field(): void
    {
        $seo = new class implements SeoFields
        {
            public function __construct(public SeoSource $source = SeoSource::Field, public bool $writable = true, public ?string $text = '') {}

            public function in(Schema $schema, EntryData $entry): array
            {
                return [new SeoField(FieldPath::of('seo')->with('description'), SeoField::DESCRIPTION, 'SEO description', 160, $this->text, $this->writable, $this->source === SeoSource::Field ? 'Excerpt' : null, $this->source)];
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

        $gaps = self::gaps([], new SessionGaps(meta: ['description' => self::DRAFT]), $seo);
        $this->assertCount(1, $gaps);
        $this->assertSame('The SEO description comes from Excerpt, which is empty. Here\'s one for this page: “'.self::DRAFT.'”', $gaps[0]->message()->english());

        $seo->text = self::DRAFT;
        $this->assertSame([], self::gaps([], null, $seo), 'Inherited and fits: left (decision 11).');

        $seo->source = SeoSource::Template;
        $seo->text = null;
        $seo->writable = false;
        $this->assertSame([], self::gaps([], null, $seo), 'A template: not checked.');

        $seo->source = SeoSource::Disabled;
        $this->assertSame([], self::gaps([], null, $seo), 'Switched off.');
    }

    public function test_an_untouched_new_entry_isnt_nagged(): void
    {
        $context = new GapContext(self::schema(), new EntryData(['title' => 'New', 'meta_description' => '']), seo: new PlainSeoFields);

        $this->assertSame([], [...(new SeoMissing)->detect($context)]);
    }

    public function test_it_is_a_standard_detector_and_never_blocks_publishing(): void
    {
        $context = new GapContext(self::schema(), new EntryData(['title' => 'Winter care', 'body' => self::BODY, 'meta_description' => '']), seo: new PlainSeoFields);
        $report = GapFinder::standard()->find($context);

        $this->assertCount(1, $report->ofKind(GapKind::SeoMissing));
        $this->assertSame([], $report->blocking());
        $this->assertSame(0, $report->count());
        $this->assertSame(1, $report->suggestions());
        $this->assertTrue((new PublishReadiness)->check($context)->ready());
    }
}
