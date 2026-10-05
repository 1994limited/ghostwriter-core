<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use PHPUnit\Framework\TestCase;

class PhotoTest extends TestCase
{
    public function test_alt_text_prefers_the_description_then_the_title_then_tags_then_the_term(): void
    {
        $this->assertSame('A bowl of soup on a table', $this->photo(title: 'Lunch', description: 'a bowl of soup on a table')->alt());
        $this->assertSame('Old stone bridge', $this->photo(title: 'old stone bridge')->alt());
        $this->assertSame('Bridge, river, stone, moss, arch', $this->photo(tags: ['bridge', 'river', 'stone', 'moss', 'arch', 'sky'])->alt());
        $this->assertSame('Raised brick beds', $this->photo(term: 'raised brick beds')->alt());
        $this->assertSame('Garden at dusk', $this->photo(term: '')->alt('garden at dusk'));
        $this->assertSame('Photo', $this->photo(term: '   ')->alt());
    }

    public function test_alt_text_is_one_line_and_cut_at_a_word(): void
    {
        $alt = $this->photo(description: "a long  description\nof ".str_repeat('a bowl of soup and bread ', 20))->alt();

        $this->assertLessThanOrEqual(Photo::ALT_LENGTH, mb_strlen($alt));
        $this->assertStringNotContainsString("\n", $alt);
        $this->assertStringStartsWith('A long description of a bowl', $alt);
    }

    public function test_the_asset_title_prefers_the_title_and_has_no_full_stop(): void
    {
        $this->assertSame('Old stone bridge', $this->photo(title: 'old stone bridge.', description: 'a bridge over a river')->assetTitle());
        $this->assertSame('A bridge over a river', $this->photo(description: 'a bridge over a river')->assetTitle());
        $this->assertSame('Stone bridge', $this->photo(term: 'stone bridge')->assetTitle());
        $this->assertSame('Éclair au chocolat', $this->photo(title: 'éclair au chocolat')->assetTitle());
        $this->assertLessThanOrEqual(30, mb_strlen($this->photo(title: str_repeat('wörd ', 20))->assetTitle(max: 30)));
    }

    public function test_the_filename_describes_the_photo_from_its_alt_text_then_the_librarys_words(): void
    {
        $this->assertSame('brown-rocks-golden-hour', $this->photo(title: 'Brown rocks during golden hour')->filenameBase());
        $this->assertSame('creme-brulee-plate', $this->photo(description: 'Crème brûlée on a plate', title: 'Dessert 4471 stock photo')->filenameBase(), 'The description (the alt text) first; stop words out.');
        $this->assertSame('walled-garden-frost', $this->photo(title: 'Woman gardening in autumn stock photo')->filenameBase(alt: 'A walled garden in frost'), 'The alt text Ghostwriter gave it wins over the library\'s title.');
        $this->assertSame('woman-gardening-autumn', $this->photo(title: 'Woman gardening in autumn, royalty free stock photo')->filenameBase(), 'Library noise is left out.');
        $this->assertSame('tree-sunset-clouds', $this->photo(tags: ['tree', 'sunset', 'clouds', 'sky'])->filenameBase());
        $this->assertSame('mended-pottery', $this->photo(title: '🙂🙂', term: 'mended pottery')->filenameBase());
        $this->assertSame('mended-pottery', $this->photo(title: 'IMG 2231', term: 'mended pottery')->filenameBase(), 'One word is no name: the next source.');
        $this->assertSame('hero-image', $this->photo(term: '')->filenameBase('Hero image'));
        $this->assertSame('roses', $this->photo(term: 'roses')->filenameBase(), 'Nothing gives two words: the first as a plain slug.');
        $this->assertSame('photo', $this->photo(term: '')->filenameBase());
        $this->assertSame('baum-sonnenuntergang', $this->photo(title: 'Ein Baum bei Sonnenuntergang, Stockfoto')->filenameBase(language: 'de'));

        $long = $this->photo(description: str_repeat('a bowl of soup on an old wooden table ', 5))->filenameBase(max: 40);
        $this->assertLessThanOrEqual(40, strlen($long));
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $long);
        $this->assertLessThanOrEqual(50, strlen($this->photo(description: str_repeat('lavender ', 20))->filenameBase()));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $this->photo(title: '東京の夜景')->filenameBase());
    }

    public function test_the_summary_tells_the_model_what_the_library_says(): void
    {
        $this->assertSame('a bowl of soup; Lunch; tags: soup, bread', $this->photo(title: 'Lunch', description: 'a bowl of soup', tags: ['soup', 'bread'])->summary());
        $this->assertSame('', $this->photo()->summary());
    }

    public function test_judging_sets_picked_and_reason_and_unjudged_clears_them(): void
    {
        $photo = $this->photo()->judged(true, '  close and warm, like the references  ');

        $this->assertTrue($photo->picked);
        $this->assertSame('close and warm, like the references', $photo->reason);
        $this->assertSame('pottery', $photo->withTerm('pottery')->term);
        $this->assertTrue($photo->withTerm('clay')->picked, 'A new term keeps the judgement.');
        $this->assertFalse($photo->unjudged()->picked);
        $this->assertNull($photo->unjudged()->reason);
        $this->assertNull($this->photo()->judged(false, ' ')->reason);
    }

    public function test_it_goes_to_an_array_and_back(): void
    {
        $photo = new Photo('pexels', '7', 'https://images.pexels.com/7-m.jpg', 'Joe on Pexels', 'https://www.pexels.com/photo/x-7/', 'Pexels licence', 'Rocks', 'brown rocks', ['rock'], 300, 200, 'https://images.pexels.com/7.jpg', 'rocks', true, 'fits');
        $array = $photo->toArray();

        $this->assertSame('https://www.pexels.com/photo/x-7/', $array['credit_url']);
        $this->assertSame('Brown rocks', $array['alt']);
        $this->assertSame('Rocks', $array['asset_title']);
        $this->assertTrue($array['picked']);
        $this->assertEquals($photo, Photo::fromArray($array));
        $this->assertEquals($photo, Photo::fromArray(json_decode((string) json_encode($array), true)));
    }

    public function test_it_reads_the_addons_older_photo_arrays(): void
    {
        $photo = Photo::fromArray(['source' => 'unsplash', 'id' => 'a', 'thumb' => 'https://x/a.jpg', 'credit' => 'Ann on Unsplash', 'credit_url' => null, 'licence' => 'Unsplash licence', 'term' => 'soup', 'picked' => true]);

        $this->assertSame('unsplash:a', $photo->key());
        $this->assertNull($photo->creditUrl);
        $this->assertNull($photo->description);
        $this->assertSame([], $photo->tags);
        $this->assertSame('Soup', $photo->alt());
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function photo(?string $title = null, ?string $description = null, array $tags = [], string $term = 'pottery'): Photo
    {
        return new Photo('unsplash', 'a', 'https://images.example.com/a.jpg', 'Ann on Unsplash', null, 'Unsplash licence', $title, $description, $tags, term: $term);
    }
}
