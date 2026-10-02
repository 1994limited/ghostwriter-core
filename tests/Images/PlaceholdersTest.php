<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\MemoryAssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * D10: one placeholder rule everywhere.
 */
#[RequiresPhpExtension('gd')]
final class PlaceholdersTest extends TestCase
{
    /**
     * A blueprint in the addons' spec arrays: an image field required, one
     * optional, one kept to PDFs, a page builder with an image in a block,
     * and a gallery builder that holds only images.
     */
    private static function schema(): Schema
    {
        $image = fn (string $handle, string $display, bool $required = false, bool $images = true, array $more = []) => ['handle' => $handle, 'type' => 'assets', 'kind' => 'reference', 'display' => $display, 'required' => $required, 'images' => $images, 'container' => 'assets'] + $more;

        return Schema::fromSpecs([
            ['handle' => 'title', 'type' => 'text', 'kind' => 'text', 'display' => 'Title', 'required' => true],
            $image('hero', 'Hero image', true, more: ['max_files' => 1]),
            $image('background', 'Background'),
            $image('brochure', 'Brochure', true, false),
            ['handle' => 'blocks', 'type' => 'replicator', 'kind' => 'blocks', 'display' => 'Blocks', 'sets' => [
                'feature' => ['display' => 'Feature', 'fields' => [
                    ['handle' => 'heading', 'type' => 'text', 'kind' => 'text', 'display' => 'Heading'],
                    $image('picture', 'Picture'),
                ]],
            ]],
            ['handle' => 'gallery', 'type' => 'replicator', 'kind' => 'blocks', 'display' => 'Gallery', 'sets' => [
                'photo' => ['display' => 'Photo', 'fields' => [$image('photo', 'Photo')]],
            ]],
        ]);
    }

    public function test_a_required_image_left_empty_gets_the_placeholder(): void
    {
        $sink = new MemoryAssetSink;
        $placeholders = new Placeholders($sink);

        $data = $placeholders->fill(['title' => 'Bulbs'], self::schema());

        $this->assertSame('assets/ghostwriter-image-placeholder.png', $data['hero'], 'One file: the field holds it alone.');
        $this->assertArrayNotHasKey('background', $data, 'Optional, and no history: left empty.');
        $this->assertArrayNotHasKey('brochure', $data, 'Not a place for a picture.');
        $this->assertSame(['Hero image'], $placeholders->filled());
        $this->assertSame('A striped placeholder marks each image still to pick: Hero image. Replace them before publishing.', $placeholders->note());
        $this->assertSame(1, $sink->drawn, 'Drawn once, and reused.');
    }

    public function test_an_image_most_records_have_is_expected_too(): void
    {
        $placeholders = new Placeholders(new MemoryAssetSink, ['background' => 0.5, 'gallery' => 0.2]);

        $data = $placeholders->fill(['hero' => 'mine.jpg'], self::schema());

        $this->assertSame('mine.jpg', $data['hero'], 'Never over one a person chose.');
        $this->assertSame(['assets/ghostwriter-image-placeholder.png'], $data['background']);
        $this->assertArrayNotHasKey('gallery', $data);
    }

    public function test_blocks_in_the_draft_have_their_own_images_filled_by_the_blocks_rate(): void
    {
        $placeholders = new Placeholders(new MemoryAssetSink, ['feature.picture' => 0.8]);

        $data = $placeholders->fill(['hero' => 'x', 'blocks' => [
            ['type' => 'feature', 'heading' => 'One'],
            ['type' => 'feature', 'heading' => 'Two', 'picture' => ['kept.jpg']],
            ['type' => 'unknown', 'heading' => 'Three'],
        ]], self::schema());

        $this->assertSame(['assets/ghostwriter-image-placeholder.png'], $data['blocks'][0]['picture']);
        $this->assertSame(['kept.jpg'], $data['blocks'][1]['picture']);
        $this->assertArrayNotHasKey('picture', $data['blocks'][2]);
        $this->assertSame(['Feature: Picture'], $placeholders->filled());
    }

    public function test_an_empty_builder_of_images_gets_one_block_where_the_cms_takes_it(): void
    {
        $placeholders = new Placeholders(new MemoryAssetSink, ['gallery' => 0.9, 'blocks' => 0.9]);

        $data = $placeholders->fill(['hero' => 'x'], self::schema());

        $this->assertSame([['type' => 'photo', 'enabled' => true, 'photo' => ['assets/ghostwriter-image-placeholder.png']]], $data['gallery']);
        $this->assertArrayNotHasKey('blocks', $data, 'A builder with other fields is left empty.');
        $this->assertSame(['Gallery'], $placeholders->filled());

        $none = (new Placeholders(new MemoryAssetSink(blocks: false), ['gallery' => 0.9]))->fill(['hero' => 'x'], self::schema());
        $this->assertArrayNotHasKey('gallery', $none);
    }

    public function test_nothing_goes_in_when_the_file_cannot_be_saved(): void
    {
        $placeholders = new Placeholders(new MemoryAssetSink(fails: true));

        $this->assertSame(['title' => 'Bulbs'], $placeholders->fill(['title' => 'Bulbs'], self::schema()));
        $this->assertNull($placeholders->note());
    }

    public function test_the_placeholder_is_soft_grey_stripes(): void
    {
        $png = Placeholders::png();
        $image = imagecreatefromstring($png);

        $this->assertNotFalse($image);
        $this->assertSame([Placeholders::WIDTH, Placeholders::HEIGHT], [imagesx($image), imagesy($image)]);

        $colours = [];

        foreach ([[0, 0], [20, 999], [60, 999], [800, 500], [1599, 999]] as [$x, $y]) {
            $colours[] = sprintf('%06X', imagecolorat($image, $x, $y));
        }

        $this->assertSame([], array_diff($colours, ['EEF0F3', 'DDE1E6']), 'Only the two greys.');
        $this->assertCount(2, array_unique($colours), 'Both greys: stripes.');
        $this->assertSame('Mark images still to choose', Placeholders::LABEL);
        $this->assertSame('placeholder_images', Placeholders::SETTING);
    }
}
