<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Seo\H1Source;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfiles;

/**
 * What every addon's render profiles must do (seo-layer-design.md §6.2):
 * the outline a preview of a seeded entry posts says the title is the
 * H1; the profile is stored by key and read back whole; two disagreeing
 * outlines don't flip a stored profile, two agreeing ones do.
 */
trait RenderProfileContract
{
    abstract protected function profiles(): RenderProfiles;

    /**
     * The addon's outline endpoint, as a render posts to it: records the
     * outline (locator.js outline()) for the key and returns the profile
     * now stored.
     *
     * @param  list<array<string, mixed>>  $outline
     */
    abstract protected function post(string $key, array $outline): RenderProfile;

    /**
     * What a preview of a seeded entry posts: its outline, captured from
     * the addon's real preview of the test site's templates.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function seededOutline(): array;

    public function test_a_seeded_entrys_preview_says_the_title_is_the_h1(): void
    {
        $profile = $this->post('contract-seeded', $this->seededOutline());

        $this->assertSame(H1Source::Title, $profile->h1);
        $this->assertSame(2, $profile->top(new Field('body', Kind::RichText)));
        $this->assertEquals($profile, $this->profiles()->get('contract-seeded'));
    }

    public function test_two_renders_must_agree_to_change_a_stored_profile(): void
    {
        $title = [['level' => 1, 'text' => 'Winter care', 'field' => 'title', 'unit' => null, 'inContent' => false]];
        $none = [['level' => 2, 'text' => 'Visits', 'field' => 'body', 'unit' => null, 'inContent' => true]];

        $this->post('contract-flip', $title);
        $this->post('contract-flip', $title);
        $this->assertSame(H1Source::Title, $this->post('contract-flip', $none)->h1, 'One odd render doesn\'t flip it.');
        $this->assertSame(H1Source::None, $this->post('contract-flip', $none)->h1, 'Two agreeing ones do.');
        $this->assertSame('no-h1', $this->profiles()->get('contract-flip')?->problem());
        $this->assertContains('contract-flip', array_map(fn (RenderProfile $profile) => $profile->key, $this->profiles()->all()));
    }
}
