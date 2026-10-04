<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReply;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\InMemoryEditReviewStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * A review is never silent. A reply that can't be read fails the review
 * with a warning (the reply itself only with logReplies), and the editor
 * is told in plain words, not the `unreadable` code. A review that
 * finishes with nothing to show says so in the log, with what was read.
 *
 * The empty reply is the one Claude gave on a clean journal entry: an
 * empty list, after several hundred tokens of thinking billed as output.
 */
final class ReviewLoggingTest extends TestCase
{
    /** Claude's real reply on a page it found nothing to change on. */
    private const EMPTY_REPLY = "<suggestions>\n{\"suggestions\": []}\n</suggestions>";

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private FakeProvider $fake;

    private InMemoryEditReviewStore $store;

    private function reviews(bool $logReplies = false): EditReviews
    {
        $logger = $this->logger();
        $studio = new Studio($this->fake, new PromptLibrary(Vocabulary::statamic()), $logger, StudioOptions::statamic(logReplies: $logReplies));

        return new EditReviews($this->store, new InMemoryLock, $studio, logger: $logger);
    }

    protected function setUp(): void
    {
        $this->fake = new FakeProvider;
        $this->store = new InMemoryEditReviewStore;
    }

    private function review(EditReviews $reviews, ReviewInput $input): EditReview
    {
        $now = new DateTimeImmutable(Northfold::NOW);

        return $reviews->run($reviews->start(Northfold::ref(), new Viewer(7), $now)->id, $input, $now);
    }

    /** The Northfold page with no candidates, as on a page the free checks find nothing on. */
    private static function clean(): ReviewInput
    {
        $input = ReviewCase::input();

        return new ReviewInput($input->context, $input->writer, [], $input->digest);
    }

    public function test_a_review_with_nothing_to_change_is_ready_and_logged(): void
    {
        $this->fake->respond('reviewer', new TextResponse(self::EMPTY_REPLY, StopReason::End, new Usage(5597, 923), 'anthropic', 'claude-opus-5-5'));

        $review = $this->review($this->reviews(), self::clean());

        $this->assertSame(ReviewStatus::Ready, $review->status);
        $this->assertNull($review->error);
        $this->assertSame([], $review->all());
        $this->fake->assertNotSent('verifier');

        $this->assertSame([
            ['info', 'Ghostwriter: the review reply had nothing to suggest.'],
            ['info', 'Ghostwriter: a review found nothing to change.'],
        ], $this->messages());
        $this->assertSame(['agent' => 'reviewer', 'part' => '1 of 1', 'candidates' => '0', 'output_tokens' => '923'], $this->logs[0]['context'], 'No reply text without logReplies.');
        $this->assertSame(['review' => $review->id, 'calls' => 1, 'read' => 0, 'candidates' => 0, 'checked' => 0, 'dropped' => [], 'output_tokens' => 923], $this->logs[1]['context']);
    }

    public function test_with_log_replies_the_empty_reply_is_logged_whole(): void
    {
        $this->fake->respond('reviewer', self::EMPTY_REPLY);

        $this->review($this->reviews(logReplies: true), self::clean());

        $this->assertSame(self::EMPTY_REPLY, $this->logs[0]['context']['reply']);
    }

    public function test_a_reply_that_cant_be_read_fails_the_review_with_a_warning(): void
    {
        $reply = 'I read the page closely and it reads well. Nothing to change.';
        $this->fake->respond('reviewer', $reply);

        $review = $this->review($this->reviews(logReplies: true), ReviewCase::input());

        $this->assertSame(ReviewStatus::Failed, $review->status);
        $this->assertSame(EditReview::UNREADABLE, $review->error);
        $this->assertSame([], $review->all());
        $this->assertSame([
            ['warning', "Ghostwriter: the review reply couldn't be read (the JSON did not parse); asking again once."],
            ['warning', "Ghostwriter: the review reply couldn't be read again (the JSON did not parse)."],
            ['warning', "Ghostwriter: a review failed: the reply couldn't be read (the JSON did not parse)."],
        ], $this->messages());
        $this->assertSame($reply, $this->logs[0]['context']['reply']);
        $this->assertCount(2, $this->fake->prompted('reviewer'), 'Asked once more, and no more.');
        $this->assertSame(['review' => $review->id, 'agent' => 'reviewer', 'calls' => 1, 'entry' => ReviewCase::input()->context->entry?->key()], $this->logs[2]['context']);
    }

    public function test_the_editor_is_told_in_plain_words(): void
    {
        $message = EditReview::errorMessage(EditReview::UNREADABLE);

        $this->assertSame('suggest.review.error.unreadable', $message?->key);
        $this->assertSame("the answer came back in a shape I couldn't read. Try again.", $message->english());
        $this->assertNull(EditReview::errorMessage('The model is busy.'), "A provider's message is already words.");
        $this->assertNull(EditReview::errorMessage(null));
    }

    public function test_a_reply_with_no_calls_read_is_not_unreadable(): void
    {
        $this->assertFalse((new SuggestionReply(calls: 0))->unreadable(), 'Unreadable always has a problem to say why.');
        $this->assertTrue((new SuggestionReply(calls: 1, problems: ['the reply was empty']))->unreadable());
    }

    /** @return list<array{0: string, 1: string}> */
    private function messages(): array
    {
        return array_map(fn (array $log) => [$log['level'], $log['message']], array_values(array_filter($this->logs, fn (array $log) => str_starts_with($log['message'], 'Ghostwriter:') && ! str_contains($log['message'], 'dropped') && ! str_contains($log['message'], 'checked in context'))));
    }

    private function logger(): AbstractLogger
    {
        $logs = &$this->logs;

        return new class($logs) extends AbstractLogger
        {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs) {}

            public function log($level, $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
