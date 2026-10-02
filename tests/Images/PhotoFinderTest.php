<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;

class PhotoFinderTest extends ImagesTestCase
{
    private FakeProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeProvider;
        $this->credentials->set('pexels', 'p-key');
        $this->route('https://images.example.com/', $this->jpegResponse());

        foreach (['raised brick beds', 'garden bed', 'carved table', 'brick planter', 'vegetable garden', 'mended pottery'] as $term) {
            $slug = str_replace(' ', '-', $term);
            $this->route('https://api.pexels.com/v1/search?query='.rawurlencode($term).'&', ['photos' => array_map(fn (int $i) => [
                'id' => "{$slug}-{$i}",
                'alt' => "{$term} number {$i}",
                'src' => ['medium' => "https://images.example.com/{$slug}-{$i}.jpg"],
                'photographer' => 'Ann',
            ], range(1, 8))]);
        }
    }

    public function test_it_chooses_searches_runs_them_and_has_them_judged(): void
    {
        $this->fake->respond('photo-researcher', 'Raised brick beds; garden bed!; carved table');
        $this->fake->respond('photo-picker', "7: brick beds full of herbs\n1: raised beds\n13: a bed\n2: more beds");

        $results = $this->finder()->find($this->context(), [self::jpeg()]);

        $this->assertSame(['raised brick beds', 'garden bed', 'carved table'], $results->terms);
        $this->assertTrue($results->judged);
        $this->assertTrue($results->withReferences);
        $this->assertFalse($results->noneFit);
        $this->assertFalse($results->retried);
        $this->assertSame(['garden-bed-1', 'raised-brick-beds-1', 'carved-table-1', 'raised-brick-beds-2'], array_map(fn (Photo $photo) => $photo->id, $results->photos));
        $this->assertCount(3, $results->picked());
        $this->assertSame('Garden bed number 1', $results->first()?->alt());

        $this->fake->assertSent('photo-researcher', fn (TextRequest $request) => str_contains($request->prompt, "Page title: Growing food in small spaces\n\nThe picture goes in: Hero: Image\n\nSummary: Raised beds for a small garden.\n\nWords in that part of the page:\n\nBuild raised brick beds.")
            && str_contains($request->prompt, "The site's own description of its images in this section:\n\nBright, natural light.")
            && $request->instructions === (new PromptLibrary(Vocabulary::statamic()))->get('photo-researcher'));
        $this->fake->assertSent('photo-picker', fn (TextRequest $request) => count($request->images) === 19);

        // Six results from each search, the shape asked of the library.
        $this->assertStringContainsString('orientation=portrait', $this->requested()[0]);
    }

    public function test_typed_searches_are_used_as_they_are(): void
    {
        $this->fake->respond('photo-picker', '1: yes');

        $results = $this->finder()->find($this->context(), [], 'Brick planter;  vegetable garden ; ');

        $this->assertSame(['brick planter', 'vegetable garden'], $results->terms);
        $this->fake->assertNotSent('photo-researcher');
        $this->assertTrue($results->judged);
        $this->assertFalse($results->withReferences);
        $this->assertSame(['brick-planter-1'], array_map(fn (Photo $photo) => $photo->id, $results->photos));

        $this->assertSame(['brick planter'], $this->finder()->find($this->context(), [], ['Brick planter'])->terms);
    }

    public function test_without_a_model_it_searches_the_title_and_ranks_nothing(): void
    {
        $this->route('https://api.pexels.com/v1/search?query=growing%20food%20in%20small%20spaces', ['photos' => []]);
        $this->route('https://api.pexels.com/v1/search?query=growing%20food&', ['photos' => [['id' => 'g1', 'src' => ['medium' => 'https://images.example.com/g1.jpg']]]]);

        $results = (new PhotoFinder($this->stock(false), null, $this->prompts()))->find($this->context(), [self::jpeg()]);

        $this->assertSame(['growing food in small spaces'], $results->terms);
        $this->assertFalse($results->judged);
        $this->assertSame([], $results->picked(), 'No badge without a judge.');
        $this->assertCount(1, $results);
    }

    public function test_when_nothing_fits_it_searches_again_with_the_models_searches(): void
    {
        $this->fake->respond('photo-picker', 'none: brick planter; vegetable garden; raised brick beds', "3: a brick planter\n8: a vegetable garden");

        $results = $this->finder()->find($this->context(), [], 'raised brick beds; carved table');

        $this->assertTrue($results->retried);
        $this->assertTrue($results->judged);
        $this->assertFalse($results->noneFit);
        $this->assertSame(['raised brick beds', 'carved table', 'brick planter', 'vegetable garden'], $results->terms, 'A search already run is not run again.');
        $this->assertSame(['brick-planter-3', 'vegetable-garden-2'], array_map(fn (Photo $photo) => $photo->id, $results->photos));
        $this->assertSame([true, true], array_map(fn (Photo $photo) => $photo->picked, $results->photos));

        $second = $this->fake->prompted('photo-picker')[1];
        $this->assertStringContainsString('second round of searches', $second->prompt);
        $this->assertStringContainsString('There are no reference images', $second->prompt, 'The second round runs without references too.');
    }

    public function test_when_the_second_round_fits_nothing_either_it_says_so(): void
    {
        $this->fake->respond('photo-picker', 'none: brick planter', 'none');

        $results = $this->finder()->find($this->context(), [self::jpeg()], 'carved table');

        $this->assertTrue($results->noneFit);
        $this->assertTrue($results->retried);
        $this->assertFalse($results->judged);
        $this->assertSame([], $results->picked());
        $this->assertCount(12, $results, 'Both rounds are offered, unranked.');
        $this->assertSame('brick-planter-1', $results->first()?->id);
        $this->assertCount(2, $this->fake->prompted('photo-picker'));
    }

    public function test_when_the_model_suggests_nothing_new_there_is_no_second_round(): void
    {
        $this->fake->respond('photo-picker', 'none: carved table');

        $results = $this->finder()->find($this->context(), [], 'carved table');

        $this->assertTrue($results->noneFit);
        $this->assertFalse($results->retried);
        $this->assertCount(6, $results);
        $this->assertCount(1, $this->fake->prompted('photo-picker'));
    }

    public function test_searches_fall_back_to_the_title_when_the_answer_is_empty(): void
    {
        $this->fake->respond('photo-researcher', ' ;;; ');
        $this->route('https://api.pexels.com/v1/search?query=growing', ['photos' => []]);

        $this->assertSame(['growing food in small spaces'], $this->finder()->searchTerms($this->context()));
    }

    public function test_terms_are_cleaned_and_capped(): void
    {
        $this->assertSame(['old stone bridge', "potter's wheel", 'café'], PhotoFinder::terms("Old  Stone Bridge!; potter's wheel\n\"Café\"; one more"));
        $this->assertSame([], PhotoFinder::terms(' ; - ; '));
    }

    public function test_results_can_be_sent_as_json(): void
    {
        $this->fake->respond('photo-picker', '1: raised beds');

        $array = $this->finder()->find($this->context(), [], 'raised brick beds')->toArray();

        $this->assertSame(['raised brick beds'], $array['terms']);
        $this->assertTrue($array['judged']);
        $this->assertFalse($array['none_fit']);
        $this->assertSame('raised-brick-beds-1', $array['photos'][0]['id']);
        $this->assertSame('raised beds', $array['photos'][0]['reason']);
        $this->assertSame('Raised brick beds number 1', $array['photos'][0]['alt']);
    }

    private function finder(): PhotoFinder
    {
        return new PhotoFinder($this->stock(false), $this->fake, $this->prompts(), $this->logger());
    }

    private function prompts(): PromptLibrary
    {
        return new PromptLibrary(Vocabulary::statamic());
    }

    private function context(): PhotoContext
    {
        return PhotoContext::make('Growing food in small spaces', 'Hero: Image', 'Build raised brick beds.', 'The whole page.', 'Raised beds for a small garden.', Shape::Portrait, 'Bright, natural light.');
    }
}
