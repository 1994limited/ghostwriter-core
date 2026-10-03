<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use NineteenNinetyFour\Ghostwriter\Core\Studio\TypeSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\Articles;

/**
 * The Studio given a structured schema, which core describes, sends what it
 * sent for the same fields described by an addon (the path until each
 * addon switches over).
 */
class StructuredLayoutTest extends StudioTestCase
{
    public function test_a_schema_is_described_by_core_and_the_requests_are_the_same(): void
    {
        $schema = Articles::schema();
        $layouts = new Layouts(LayoutOptions::craft());
        $pattern = $layouts->patterns()->find($schema, Articles::entries());

        $structured = Layout::fromSchema($schema, $pattern, $layouts->describer());
        $described = Layout::fromPattern((new SchemaDescriber(LayoutOptions::craft()))->describe($schema, $pattern->toArray()), $pattern->toArray());

        $this->assertSame($described->fields, $structured->fields);
        $this->assertSame($described->examples, $structured->examples);
        $this->assertSame(3, $structured->studied);
        $this->assertSame($schema, $structured->schema);
        $this->assertNull($described->schema);
        $this->assertEquals($structured, $layouts->layout($schema, Articles::entries()));

        $requests = [];

        foreach ([$structured, $described] as $layout) {
            $this->fake = new FakeProvider;
            $this->fake->respond('writer', self::reply("<draft>\ntitle: Hi\n</draft>"));
            $this->fake->respond('type-analyst', self::reply("<type>\ntitle: Project\ndescription: One project.\nquestions:\n  - handle: what\n    label: What?\n</type>"));

            $studio = $this->studio();
            $studio->write(new Conversation([['role' => 'user', 'content' => 'Write about orchards.']]), new WriterContext(new ContentKind('article', 'Article'), '', $layout, ''));
            $studio->analyseType(new TypeSurvey('Articles', 'articles', $layout));

            $requests[] = RequestLog::records($this->fake->requests());
        }

        // A schema shows the writer where extras could go; fields described
        // by an addon give it no schema, so the writer is told nothing more.
        $extras = $this->studio()->extrasSection(new WriterContext(new ContentKind('article', 'Article'), '', $structured, ''));
        $this->assertStringStartsWith("\n\n## Extras you may prepare\n", $extras);
        $this->assertStringContainsString('- `pull_quote`: ', $extras);
        $this->assertStringEndsWith($extras, $requests[0][0]['instructions']);
        $requests[0][0]['instructions'] = substr($requests[0][0]['instructions'], 0, -strlen($extras));

        $this->assertSame($requests[0], $requests[1]);
        $this->assertStringContainsString("- `title` (short text, required)\n", $requests[0][0]['instructions'].$requests[0][0]['prompt']);
    }

    public function test_a_pattern_array_is_taken_too(): void
    {
        $layout = Layout::fromSchema(Articles::schema(), ['entries' => 4, 'examples' => [['title' => 'Old']]]);

        $this->assertSame(4, $layout->studied);
        $this->assertSame([['title' => 'Old']], $layout->examples);
        $this->assertStringStartsWith('- `title` (short text, required)', $layout->fields);
    }
}
