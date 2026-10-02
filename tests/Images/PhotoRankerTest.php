<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Limits;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\StaticProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoRanker;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;

class PhotoRankerTest extends ImagesTestCase
{
    private FakeProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeProvider;
        $this->route('https://images.example.com/', $this->jpegResponse(60, 40));
    }

    public function test_with_references_it_matches_style_and_subject_and_drops_the_misses(): void
    {
        $this->fake->respond('photo-picker', "2: same warm light as the references\n5: hands at a wheel\n3: a finished bowl\n4: clay on a bench");
        $candidates = $this->candidates();

        $ranking = $this->ranker()->rank($candidates, $this->context(), [self::jpeg(800, 600), new Image(self::jpeg(), 'image/jpeg')]);

        $this->assertTrue($ranking->judged);
        $this->assertFalse($ranking->noneFit);
        $this->assertTrue($ranking->withReferences);

        // One from each search first (b, e from "clay", then c); 1 and 6 were misses.
        $this->assertSame(['b', 'e', 'c', 'd'], $this->ids($ranking->photos));
        $this->assertSame([true, true, true, false], array_map(fn (Photo $photo) => $photo->picked, $ranking->photos));
        $this->assertSame('same warm light as the references', $ranking->photos[0]->reason);
        $this->assertSame('clay on a bench', $ranking->photos[3]->reason);

        $this->fake->assertSent('photo-picker', function (TextRequest $request) {
            return count($request->images) === 8
                && str_contains($request->prompt, 'The first 2 image(s) are the references')
                && str_contains($request->prompt, 'Choose by style and subject')
                && str_contains($request->prompt, 'reply with none, a colon and three better searches')
                && str_contains($request->prompt, '2. from the search "pottery"; the library describes it as: a hand-thrown bowl')
                && str_contains($request->prompt, 'and the picture goes in Hero: Image')
                && str_contains($request->prompt, 'Words in that part of the page:')
                && str_contains($request->prompt, "The site's own description of its images in this section:\nWarm close-ups.")
                && $request->instructions === (new PromptLibrary(Vocabulary::craft()))->get('photo-picker')
                && $request->timeout === 60
                && $request->resolvedEffort()?->value === 'low';
        });
    }

    public function test_without_references_it_still_judges_the_subject(): void
    {
        $this->fake->respond('photo-picker', '3: a bowl, as the page describes');

        $ranking = $this->ranker()->rank($this->candidates(), $this->context());

        $this->assertTrue($ranking->judged);
        $this->assertFalse($ranking->withReferences);
        $this->assertSame(['c'], $this->ids($ranking->photos));
        $this->assertTrue($ranking->photos[0]->picked);

        $this->fake->assertSent('photo-picker', fn (TextRequest $request) => count($request->images) === 6
            && str_contains($request->prompt, 'There are no reference images. The 6 images are the candidates')
            && str_contains($request->prompt, 'Choose by subject alone'));
    }

    public function test_a_plain_list_of_numbers_from_an_older_prompt_is_still_read(): void
    {
        $this->fake->respond('photo-picker', '6, 1, 2');

        $ranking = $this->ranker()->rank($this->candidates(), $this->context(), [self::jpeg()]);

        $this->assertSame(['f', 'a', 'b'], $this->ids($ranking->photos));
        $this->assertSame([true, true, true], array_map(fn (Photo $photo) => $photo->picked, $ranking->photos));
        $this->assertNull($ranking->photos[0]->reason);
    }

    public function test_none_fitting_brings_better_searches_once(): void
    {
        $this->fake->respond('photo-picker', "none: Mended pottery, gold; `restored classic car`;\nold stone bridge.");

        $ranking = $this->ranker()->rank($this->candidates(), $this->context());

        $this->assertTrue($ranking->judged);
        $this->assertTrue($ranking->noneFit);
        $this->assertSame(['mended pottery gold', 'restored classic car', 'old stone bridge'], $ranking->retryTerms);
        $this->assertSame([], array_filter($ranking->photos, fn (Photo $photo) => $photo->picked), 'Nothing is picked when nothing fits.');

        $second = $this->ranker()->rank($this->candidates(), $this->context(), mayRetry: false);
        $this->assertTrue($second->noneFit);
        $this->assertSame([], $second->retryTerms);
        $this->fake->assertSent('photo-picker', fn (TextRequest $request) => str_contains($request->prompt, 'second round of searches'));
    }

    public function test_an_answer_that_cannot_be_read_leaves_them_unranked(): void
    {
        $this->fake->respond('photo-picker', 'I like the second one best.');

        $ranking = $this->ranker()->rank($this->candidates(), $this->context());

        $this->assertFalse($ranking->judged);
        $this->assertSame([], array_filter($ranking->photos, fn (Photo $photo) => $photo->picked));
        $this->assertCount(6, $ranking->photos);
        $this->assertStringContainsString('could not be read', $this->logs[0]['message']);

        $this->fake->reset()->respond('photo-picker', '99: not one of them');
        $this->assertFalse($this->ranker()->rank($this->candidates(), $this->context())->judged);
    }

    public function test_without_a_model_nothing_is_judged_or_picked(): void
    {
        $ranking = (new PhotoRanker($this->stock(), null, $this->prompts()))->rank($this->candidates(), $this->context(), [self::jpeg()]);

        $this->assertFalse($ranking->judged);
        $this->assertSame(['a', 'c', 'e', 'b', 'd', 'f'], $this->ids($ranking->photos), 'The top result of each search leads.');
        $this->assertSame([], array_filter($ranking->photos, fn (Photo $photo) => $photo->picked));
        $this->assertSame([], $this->http->requests, 'No thumbnails are fetched for nothing.');

        $providers = new Providers(new ArrayCredentials, $this->http, new StaticProviderSettings);
        $this->assertFalse((new PhotoRanker($this->stock(), $providers, $this->prompts()))->canJudge());

        $providers->fake(FakeProvider::withoutKeys());
        $this->assertFalse((new PhotoRanker($this->stock(), $providers, $this->prompts()))->rank($this->candidates(), $this->context())->judged);

        $providers->fake()->unconfigured(false, false)->respond('photo-picker', '1: yes');
        $this->assertTrue((new PhotoRanker($this->stock(), $providers, $this->prompts()))->rank($this->candidates(), $this->context())->judged, 'The registry\'s model is used when it has a key.');
    }

    public function test_a_failed_call_leaves_them_unranked(): void
    {
        $this->fake->failWith('photo-picker', new Overloaded('Anthropic is busy.', 'anthropic', 529));

        $ranking = $this->ranker()->rank($this->candidates(), $this->context());

        $this->assertFalse($ranking->judged);
        $this->assertCount(6, $ranking->photos);
        $this->assertStringContainsString('Anthropic is busy', $this->logs[0]['message']);
    }

    public function test_candidates_whose_thumbnails_wont_load_are_not_shown_or_kept(): void
    {
        $this->route('https://images.example.com/b.jpg', $this->http->response(404));
        $this->fake->respond('photo-picker', '1: yes');

        $ranking = $this->ranker()->rank($this->candidates(), $this->context());

        $this->fake->assertSent('photo-picker', fn (TextRequest $request) => count($request->images) === 5 && ! str_contains($request->prompt, '6. from'));
        $this->assertSame(['a'], $this->ids($ranking->photos));

        $this->route('https://images.example.com/', $this->http->response(500));
        $this->fake->reset();
        $this->assertFalse($this->ranker()->rank([self::photo('z1'), self::photo('z2')], $this->context())->judged);
        $this->fake->assertNothingSent();
    }

    public function test_no_more_images_are_sent_than_one_request_may_carry(): void
    {
        $this->fake->respond('photo-picker', '1: yes');
        $many = array_map(fn (int $i) => self::photo("p{$i}", 'term '.($i % 3)), range(1, 30));

        $this->ranker()->rank($many, $this->context(), [self::jpeg(), self::jpeg(), self::jpeg(), self::jpeg()]);

        $request = $this->fake->prompted('photo-picker')[0];
        $this->assertCount(Limits::MAX_IMAGES, $request->images);
        $this->assertStringContainsString('The first 3 image(s) are the references: the pictures already used in this place on other pages. The 21 after them', $request->prompt);
        $this->assertTrue(Limits::fits($request->images));
    }

    public function test_unranked_takes_one_from_each_search_first(): void
    {
        $photos = PhotoRanker::unranked([
            self::photo('a', 'x')->judged(true, 'old'), self::photo('b', 'x'), self::photo('c', 'x'), self::photo('d', 'y'),
        ]);

        $this->assertSame(['a', 'd', 'b', 'c'], $this->ids($photos));
        $this->assertFalse($photos[0]->picked);
        $this->assertNull($photos[0]->reason);
        $this->assertSame([], PhotoRanker::unranked([]));
    }

    private function ranker(): PhotoRanker
    {
        return new PhotoRanker($this->stock(), $this->fake, $this->prompts(), $this->logger());
    }

    private function prompts(): PromptLibrary
    {
        return new PromptLibrary(Vocabulary::craft());
    }

    private function context(): PhotoContext
    {
        return PhotoContext::make('Repairing old pots', 'Hero: Image', 'We mend broken pottery with gold.', 'The whole page.', '', Shape::Landscape, 'Warm close-ups.');
    }

    /**
     * @return array<int, Photo>
     */
    private function candidates(): array
    {
        return [
            self::photo('a', 'pottery', description: 'a carved wooden table'),
            self::photo('b', 'pottery', description: 'a hand-thrown bowl'),
            self::photo('c', 'gold repair'),
            self::photo('d', 'gold repair'),
            self::photo('e', 'clay'),
            self::photo('f', 'clay'),
        ];
    }

    /**
     * @param  array<int, Photo>  $photos
     * @return array<int, string>
     */
    private function ids(array $photos): array
    {
        return array_map(fn (Photo $photo) => $photo->id, $photos);
    }
}
