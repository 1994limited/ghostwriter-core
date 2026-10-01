<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Text;

use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * YAML written by a model: prose with apostrophes, quotation marks and
 * colons in it, which strict YAML reads as syntax. Ported from the Craft
 * addon's LenientYamlTest.
 */
class LenientYamlTest extends TestCase
{
    public function test_prose_that_breaks_yaml_quoting_is_still_read(): void
    {
        $data = LenientYaml::parse(implode("\n", [
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

        $this->assertSame('How to Brief a Web Agency', $data['title']);
        $this->assertSame("Send us something even if it's rough. Here's what to put in it:", $data['intro']);
        $this->assertSame('What to send: a page is plenty', $data['summary']);
        $this->assertSame('She said "go" and we went', $data['quote']);
        $this->assertSame("It's fine", $data['blocks'][0]['heading']);
        $this->assertSame("It's a block: nothing here is touched.\n'Quoted' too.\n", $data['blocks'][0]['body']);
        $this->assertSame("Don't skip this", $data['blocks'][1]);
    }

    public function test_a_value_wrapped_over_lines_is_still_read(): void
    {
        $data = LenientYaml::parse(implode("\n", [
            'title: Who Owns What',
            'blocks:',
            '  - type: text',
            '    why: "Launch" and "Support" both answer it: whose name is on the code',
            '      and who holds the keys after launch.',
            '    notes: Set it out plainly: code, hosting, support',
            '      and how that sits alongside the relationship.',
            '    tint: blue',
        ]));

        $this->assertSame('"Launch" and "Support" both answer it: whose name is on the code and who holds the keys after launch.', $data['blocks'][0]['why']);
        $this->assertSame('Set it out plainly: code, hosting, support and how that sits alongside the relationship.', $data['blocks'][0]['notes']);
        $this->assertSame('blue', $data['blocks'][0]['tint']);
    }

    public function test_valid_yaml_is_read_as_it_stands(): void
    {
        $this->assertSame(['a' => ['b' => [1, 2]], 'c' => 'd: e'], LenientYaml::parse("a:\n  b: [1, 2]\nc: 'd: e'"));
    }

    public function test_text_that_cannot_be_repaired_reports_the_original_complaint(): void
    {
        $this->expectException(ParseException::class);

        LenientYaml::parse("title: A\n  bad: [indent");
    }

    public function test_a_list_item_starting_with_a_dash_and_a_unicode_space_is_not_requoted(): void
    {
        // In UTF-8 mode \s matches the no-break space, so this reads as a
        // nested list item rather than a value to quote.
        $this->assertSame("- \u{00A0}a: b", LenientYaml::repair("- \u{00A0}a: b"));
    }
}
