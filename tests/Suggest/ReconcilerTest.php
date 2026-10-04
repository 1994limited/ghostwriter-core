<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Anchor;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Reason;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReasonSource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Reconciler;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionState;
use PHPUnit\Framework\TestCase;

/**
 * The Reconciler on a whole sentence: an Out of date rewrite that drops
 * the dated words is close enough to the old sentence to be found by a
 * fuzzy match, and must still count as Done.
 */
final class ReconcilerTest extends TestCase
{
    private const OLD = 'New for 2023: two open days a year at the studio garden, in May and September.';

    private const NEW = 'Two open days a year at the studio garden, in May and September.';

    private static function stateAfter(string $saved): SuggestionState
    {
        $suggestion = new Suggestion(
            id: 's1',
            category: Category::OutOfDate,
            anchor: new Anchor(AnchorScope::Range, FieldPath::of('body'), 'Body', new TextQuote(self::OLD)),
            reason: new Reason('Dated.', ReasonSource::Check),
            replacement: self::NEW,
        );
        $review = new EditReview('r1', Northfold::ref(), suggestions: [$suggestion->toArray()]);
        $context = new CheckContext(
            gaps: new GapContext(schema: new Schema([new Field('body', Kind::LongText, 'Body', type: 'markdown')]), entry: new EntryData(['body' => $saved])),
            now: new DateTimeImmutable(FreeChecksTest::NOW),
            updatedAt: new DateTimeImmutable(FreeChecksTest::UPDATED),
            language: 'en',
        );

        return (new Reconciler)->reconcile($review, $context, new DateTimeImmutable(FreeChecksTest::NOW))->all()[0]->state;
    }

    public function test_a_rewrite_that_drops_the_dated_words_is_done_though_it_fuzzily_matches_the_old_sentence(): void
    {
        $this->assertSame(SuggestionState::Done, self::stateAfter("Garden open days\n\n".self::NEW."\n\nPlaces are free but limited."));
    }

    public function test_the_old_sentence_still_there_is_not_done(): void
    {
        $this->assertSame(SuggestionState::Open, self::stateAfter("Garden open days\n\n".self::OLD."\n\nPlaces are free but limited."));
    }

    public function test_a_light_edit_of_the_old_sentence_is_neither_done_nor_stale(): void
    {
        $this->assertSame(SuggestionState::Open, self::stateAfter("Garden open days\n\nNew for 2023: two open days a year at our studio garden, in May and September.\n\nPlaces are free but limited."));
    }
}
