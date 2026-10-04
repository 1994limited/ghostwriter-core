<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkStatus;

/**
 * What every LinkProbe must do. The addon builds its probe over a faked
 * HTTP client (Laravel's Http::fake(), Guzzle's MockHandler) that answers
 * each address as `$answers` says: a status code for every request, or
 * `['HEAD' => 405, 'GET' => 206]` per method, or 'dns' (the name doesn't
 * resolve) or 'timeout'.
 */
trait LinkProbeContract
{
    /**
     * @param  array<string, int|string|array<string, int>>  $answers
     */
    abstract protected function linkProbe(array $answers): LinkProbe;

    public function test_a_page_that_answers_is_ok(): void
    {
        $result = $this->linkProbe(['https://example.org/page' => 200])->probe('https://example.org/page', 10);

        $this->assertSame(LinkStatus::Ok, $result->status);
        $this->assertSame(200, $result->code);
        $this->assertNotNull($result->checkedAt);
        $this->assertSame('https://example.org/page', $result->url);
    }

    public function test_not_found_and_gone_are_broken(): void
    {
        $probe = $this->linkProbe(['https://example.org/a' => 404, 'https://example.org/b' => 410]);

        $this->assertSame(LinkStatus::Broken, $probe->probe('https://example.org/a', 10)->status);
        $this->assertSame(LinkStatus::Broken, $probe->probe('https://example.org/b', 10)->status);
    }

    public function test_a_site_that_refuses_head_is_asked_with_get(): void
    {
        $result = $this->linkProbe(['https://example.org/page' => ['HEAD' => 405, 'GET' => 206]])->probe('https://example.org/page', 10);

        $this->assertSame(LinkStatus::Ok, $result->status);
    }

    public function test_what_says_nothing_about_the_page_is_unknown(): void
    {
        $probe = $this->linkProbe(['https://example.org/busy' => 503, 'https://example.org/slow' => 'timeout', 'https://example.org/limited' => 429, 'https://example.org/private' => 401]);

        foreach (['busy', 'slow', 'limited', 'private'] as $page) {
            $this->assertSame(LinkStatus::Unknown, $probe->probe("https://example.org/{$page}", 10)->status, $page);
        }
    }

    public function test_a_name_that_no_longer_resolves_is_broken(): void
    {
        $this->assertSame(LinkStatus::Broken, $this->linkProbe(['https://gone.example/page' => 'dns'])->probe('https://gone.example/page', 10)->status);
    }
}
