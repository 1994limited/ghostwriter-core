<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\Utf8;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the Statamic addon's DraftTest (the Bard cases stay there).
 */
class DraftTest extends TestCase
{
    public function test_it_reads_a_yaml_draft(): void
    {
        $draft = Draft::parse("title: What Does a Website Cost?\nsummary: Why quotes vary.\npage_builder:\n  - type: long_form\n    content: |\n      ## Why?\n\n      Because scope differs.\n");

        $this->assertSame('What Does a Website Cost?', $draft->title());
        $this->assertSame('long_form', $draft->data['page_builder'][0]['type']);
        $this->assertStringContainsString("## Why?\n\nBecause scope differs.", $draft->data['page_builder'][0]['content']);

        // Title 5, summary 3, content 4 (the heading mark is not a word); block types are not counted.
        $this->assertSame(12, $draft->wordCount());
    }

    public function test_it_unwraps_a_draft_the_model_put_in_a_code_fence(): void
    {
        $this->assertSame('Fenced', Draft::parse("```yaml\ntitle: Fenced\n```")->title());
        $this->assertSame('Fenced', Draft::parse("```\ntitle: Fenced\nsummary: Two lines\n```")->title());
        $this->assertSame('Tight', Draft::parse("```yml\ntitle: Tight```")->title());
        $this->assertSame("title: Raw\nsummary: Kept", Draft::parse("```yaml\ntitle: Raw\nsummary: Kept\n```")->raw);
    }

    public function test_it_explains_what_is_wrong_with_a_bad_draft(): void
    {
        foreach ([
            ['summary: No title', 'needs a title'],
            ["title: '   '", 'needs a title'],
            ["- just\n- a list", 'should be a list of fields'],
            ['just words', 'should be a list of fields'],
            ["title: A\n  bad: [indent", 'not valid YAML'],
        ] as [$raw, $message]) {
            try {
                Draft::parse($raw);
                $this->fail("Expected \"{$message}\".");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_prose_that_breaks_yaml_quoting_is_still_read(): void
    {
        $draft = Draft::parse(implode("\n", [
            "title: 'How to Brief a Web Agency'",
            "intro: 'Send us something even if it's rough. Here's what to put in it:'",
            'summary: What to send: a page is plenty',
            'quote: "She said "go" and we went"',
            'blocks:',
            '  - type: text',
            "    heading: 'It's fine'",
            '    body: |',
            "      It's a block: nothing here is touched.",
            "      'Quoted' too.",
            "  - 'Don't skip this'",
        ]));

        $this->assertSame("Send us something even if it's rough. Here's what to put in it:", $draft->data['intro']);
        $this->assertSame('What to send: a page is plenty', $draft->data['summary']);
        $this->assertSame('She said "go" and we went', $draft->data['quote']);
        $this->assertSame("It's fine", $draft->data['blocks'][0]['heading']);
        // Older symfony/yaml (before 7.4.19 and 8.1) drops the clipped block's
        // final newline when a less-indented list item follows.
        $this->assertSame("It's a block: nothing here is touched.\n'Quoted' too.", rtrim($draft->data['blocks'][0]['body'], "\n"));
        $this->assertSame("Don't skip this", $draft->data['blocks'][1]);
    }

    public function test_text_in_any_script_is_read_and_bad_bytes_are_scrubbed(): void
    {
        $draft = Draft::parse("title: 'Café “Zoë” – 東京'\nsummary: naïve résumé: with colon\n");

        $this->assertSame('Café “Zoë” – 東京', $draft->title());
        $this->assertSame('naïve résumé: with colon', $draft->data['summary']);

        $scrubbed = Utf8::scrub(['ok' => 'Zoë', 'bad' => "caf\xE9", 'nested' => ['n' => "\xFF"], 'number' => 3]);

        $this->assertSame('Zoë', $scrubbed['ok']);
        $this->assertSame(3, $scrubbed['number']);
        $this->assertTrue(mb_check_encoding($scrubbed['bad'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($scrubbed['nested']['n'], 'UTF-8'));
        $this->assertNotFalse(json_encode($scrubbed));
    }

    public function test_a_draft_with_a_bad_byte_is_reported_as_bad_yaml(): void
    {
        // The fence is still unwrapped (the pattern falls back to bytes), so
        // the complaint is about the YAML, not the shape of the draft.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not valid YAML');

        Draft::parse("```yaml\ntitle: \"Caf\xE9\"\n```");
    }
}
