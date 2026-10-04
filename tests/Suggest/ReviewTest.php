<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Anchor;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Category;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Needs;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReasonSource;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewPrompt;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReply;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionValidator;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ValidatedReview;
use PHPUnit\Framework\TestCase;

/**
 * The review call on the mockup's Services page, through FakeProvider:
 * one `reviewer` call, every free finding a candidate it keeps or drops,
 * and the mockup's seven suggestions kept.
 */
final class ReviewTest extends TestCase
{
    public function test_one_call_and_the_mockups_seven_suggestions(): void
    {
        $fake = new FakeProvider;
        $fake->respond('reviewer', ReviewCase::reply());
        $input = ReviewCase::input();

        $this->assertSame(1, $input->calls());
        $result = ReviewCase::studio($fake)->suggestEdits($input);
        $review = (new SuggestionValidator)->validate($result->value, $input);

        $this->assertCount(1, $fake->requests());
        $fake->assertSent('reviewer', fn (TextRequest $r) => count($r->images) === 1 && str_contains($r->instructions, 'What this voice never does'));

        $this->assertSame([
            'out-of-date' => 'Winter care visits',
            'voice' => 'We design gardens and help them grow',
            'fact-to-check' => null,
            'clarity' => 'First we visit the garden, then we draw a concept.',
            'link' => 'a walled garden we designed in Corbridge',
            'accessibility' => 'Stone, gravel and timber samples laid out on a workbench',
            'seo' => 'From a planting plan to a full design and build, across Northumberland, Durham and the Tyne Valley.',
        ], array_combine(array_map(fn (Suggestion $s) => $s->category->value, $review->suggestions), array_map(fn (Suggestion $s) => $s->replacement, $review->suggestions)));
        $this->assertSame([], $review->dropped);
        $this->assertSame(7, $review->written());

        $byCategory = [];

        foreach ($review->suggestions as $suggestion) {
            $byCategory[$suggestion->category->value] = $suggestion;
        }

        $this->assertSame(ReasonSource::VoiceGuide, $byCategory['voice']->reason->source);
        $this->assertSame('What this voice never does', $byCategory['voice']->reason->detail);
        $this->assertSame(['Gardens designed, planted and looked after', 'We design and plant gardens'], $byCategory['voice']->alternatives);
        $this->assertSame('team of 8', $byCategory['fact-to-check']->fact?->fill('8'));
        $this->assertSame('team', $byCategory['fact-to-check']->fact->without);
        $this->assertSame('entry::e12', $byCategory['link']->link?->target);
        $this->assertFalse($byCategory['link']->link->free);
        $this->assertSame(AnchorScope::Asset, $byCategory['accessibility']->anchor->scope);
        $this->assertSame('out-of-date|page_builder/#h1/eyebrow|new for 2024 winter care visits|0', $byCategory['out-of-date']->id, 'A fixed finding keeps its id: the sentence it is in.');
        $this->assertSame(['Our winter care visits', 'Winter care visits, every year'], $byCategory['out-of-date']->alternatives);
        $this->assertSame('voice|page_builder/#h1/heading|we leverage our expertise to deliver bespoke garden solutions|0', $byCategory['voice']->id);
    }

    public function test_the_prompt_is_pinned(): void
    {
        $fake = new FakeProvider;
        $fake->respond('reviewer', ReviewCase::reply());
        ReviewCase::studio($fake)->suggestEdits(ReviewCase::input());

        $record = RequestLog::records($fake->requests())[0];
        $path = dirname(__DIR__).'/Fixtures/suggest/reviewer-services.json';
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
            file_put_contents($path, $json);
        }

        $this->assertSame((string) file_get_contents($path), $json, 'Write the fixture with GHOSTWRITER_UPDATE_FIXTURES=1.');
        $this->assertSame(16000, $record['maxTokens']);
        $this->assertSame('high', $record['effort']);
    }

    public function test_without_a_reply_nothing_is_shown(): void
    {
        $input = ReviewCase::input();
        $review = (new SuggestionValidator)->validate(new SuggestionReply, $input);

        $this->assertSame([], $review->suggestions, 'No suggestion reaches the editor without the model judging it.');
        $this->assertSame(['unanswered' => 6], $review->dropped);
        $this->assertSame([], $review->checked);
    }

    public function test_every_finding_is_a_candidate_with_its_heading_and_dated_words(): void
    {
        $input = ReviewCase::input();
        $prompt = ReviewPrompt::render($input, $input->batches()[0]);

        $this->assertStringContainsString('f1 out-of-date u2 "New for 2024: winter care visits": Says “New for 2024” in 2026. Dated words: "New for 2024". If kept: rewrite the whole sentence.', $prompt);
        $this->assertStringContainsString('f3 clarity u4 under "Garden design" "In terms of', $prompt, 'The heading it sits under.');
        $this->assertStringContainsString('f4 link u4 under "Garden design" "our 2023 show garden"', $prompt);
        $this->assertCount(6, $input->numbered(), 'Every finding, the long-sentence hint included.');
    }

    public function test_a_link_field_to_a_deleted_page_is_a_candidate_too(): void
    {
        $finding = new Finding('link|related||0', Category::Link, 'link-broken', new Anchor(AnchorScope::Field, FieldPath::parse('related'), 'Related'), Needs::Nothing, new Message('suggest.finding.link-broken-field', ['label' => 'Related']));
        $context = Northfold::context();
        $input = new ReviewInput($context, ReviewCase::writer(), [$finding]);

        $this->assertSame(['f1' => $finding], $input->numbered());
        $this->assertStringContainsString('If kept: the link alone, no words.', ReviewPrompt::render($input, $input->batches()[0]));
        $kept = (new SuggestionValidator)->validate(new SuggestionReply([['batch' => 0, 'item' => ['finding' => 'f1', 'category' => 'link', 'reason' => 'The page it pointed at is gone.', 'source' => ['kind' => 'finding']]]]), $input);
        $this->assertCount(1, $kept->suggestions, 'Kept with no words: the editor picks the page.');
    }

    public function test_every_kept_anchor_is_found_in_its_field(): void
    {
        $fake = new FakeProvider;
        $fake->respond('reviewer', ReviewCase::reply());
        $input = ReviewCase::input();
        $review = (new SuggestionValidator)->validate(ReviewCase::studio($fake)->suggestEdits($input)->value, $input);

        foreach ($review->suggestions as $suggestion) {
            if ($suggestion->anchor->scope === AnchorScope::Range) {
                $text = $input->context->textAt($suggestion->anchor->path->toString());
                $this->assertNotNull($text);
                $this->assertNotNull((new QuoteFinder)->find($suggestion->anchor->quote, $text->markdown, $suggestion->anchor->occurrence, markdown: true), $suggestion->id);
            }
        }
    }

    public function test_an_unreadable_reply_is_logged_and_gives_nothing(): void
    {
        $fake = new FakeProvider;
        $fake->respond('reviewer', 'I think the page is lovely.');
        $input = ReviewCase::input();
        $reply = ReviewCase::studio($fake)->suggestEdits($input)->value;

        $this->assertTrue($reply->unreadable());
        $this->assertSame([], (new SuggestionValidator)->validate($reply, $input)->suggestions);
    }

    public function test_a_refused_image_is_not_attached(): void
    {
        $context = Northfold::context(entry: Northfold::entry(['page_builder' => [
            ['id' => 'i1', 'type' => 'image', 'image' => 'assets::GettyImages-123456.jpg'],
        ]]));
        $fake = new FakeProvider;
        $fake->respond('reviewer', '<suggestions>{"suggestions": []}</suggestions>');
        $reply = ReviewCase::studio($fake)->suggestEdits(ReviewCase::input($context))->value;

        $this->assertSame([], $reply->attached);
        $fake->assertSent('reviewer', fn (TextRequest $r) => $r->images === [] && str_contains($r->prompt, 'attached="0"') && str_contains($r->prompt, 'The picture is not attached. If kept: no replacement.'));
    }

    public function test_the_result_is_one_validated_review(): void
    {
        $this->assertInstanceOf(ValidatedReview::class, (new SuggestionValidator)->validate(new SuggestionReply, ReviewCase::input()));
        $this->assertSame(Category::OutOfDate, Category::from('out-of-date'));
    }
}
