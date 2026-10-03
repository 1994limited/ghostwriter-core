<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

/**
 * What every addon's preview must do with the invisible markers: a value
 * stored as the addon's apply path stores it, marked by PreviewMarkers and
 * printed as the site's templates print it (Statamic's Bard and markdown
 * augmentation, Craft's CKEditor HTML, Filament's RichContentRenderer),
 * still carries every marker, and with the markers taken out is exactly
 * the page the unmarked value prints. Apply's own data never holds one.
 *
 * Shapes, as MarkerRoundTripContract's:
 * - `rich`: rich text (Bard, CKEditor, RichEditor);
 * - `markdown`: a markdown field;
 * - `plain`: a text input.
 *
 * An addon with no markdown field leaves `markdown` out of markerShapes().
 */
trait PreviewMarkerContract
{
    /**
     * A draft's markdown for a field of this shape, through the addon's
     * apply path, as the field stores it.
     *
     * @param  'rich'|'markdown'|'plain'  $shape
     */
    abstract protected function storedValue(string $markdown, string $shape): mixed;

    /**
     * A stored value printed as the site's templates print it.
     *
     * @param  'rich'|'markdown'|'plain'  $shape
     */
    abstract protected function renderValue(mixed $stored, string $shape): string;

    /**
     * @return list<'rich'|'markdown'|'plain'>
     */
    protected function markerShapes(): array
    {
        return ['rich', 'markdown', 'plain'];
    }

    /**
     * The field a value of this shape is in. Override to give the addon's
     * own type or meta.
     *
     * @param  'rich'|'markdown'|'plain'  $shape
     */
    protected function previewField(string $shape): Field
    {
        return match ($shape) {
            'rich' => new Field('copy', Kind::RichText, 'Copy'),
            'markdown' => new Field('copy', Kind::LongText, 'Copy', type: 'markdown'),
            'plain' => new Field('copy', Kind::Text, 'Copy'),
        };
    }

    public function test_every_shape_carries_its_markers_through_rendering(): void
    {
        foreach ($this->markerShapes() as $shape) {
            $markdown = $shape === 'plain' ? 'Four visits between November and February' : self::previewText();
            [$marked, $stored] = $this->markOne($markdown, $shape);
            $page = $this->renderValue($marked['page_builder'][0]['copy'], $shape);

            $this->assertContains('b1.1', array_column(PreviewMarkers::decode($page), 'payload'), "The {$shape} value lost its marker:\n{$page}");
            $this->assertSame($this->renderValue($stored, $shape), PreviewMarkers::stripText($page), "Without its markers, the {$shape} page isn't the unmarked page.");
        }
    }

    public function test_each_section_of_rich_text_carries_a_marker(): void
    {
        foreach (array_intersect($this->markerShapes(), ['rich', 'markdown']) as $shape) {
            [$marked] = $this->markOne(self::previewText(), $shape);
            $page = $this->renderValue($marked['page_builder'][0]['copy'], $shape);

            $this->assertSame(['b1.1', 's1', 's2', 's3'], array_column(PreviewMarkers::decode($page), 'payload'), "The {$shape} page:\n{$page}");
        }
    }

    public function test_apply_data_never_holds_a_marker(): void
    {
        foreach ($this->markerShapes() as $shape) {
            [$marked, $stored] = $this->markOne(self::previewText(), $shape);

            $this->assertFalse(PreviewMarkers::contains($stored), "Apply's {$shape} value holds a marker.");
            $this->assertTrue(PreviewMarkers::contains($marked));
            $this->assertSame($stored, PreviewMarkers::strip($marked['page_builder'][0]['copy']), "Taking the markers out of the {$shape} value doesn't give apply's value.");
        }
    }

    /**
     * Two sections under a lead, with a list and emphasis.
     */
    protected static function previewText(): string
    {
        return "Winter is when a garden is set up for the year.\n\n## What the visits are\n\n**November: Cut back.** Prune the shrubs that need it.\n\n## Who it suits\n\n- Gardens with *mixed* borders\n- Young trees";
    }

    /**
     * @param  'rich'|'markdown'|'plain'  $shape
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function markOne(string $markdown, string $shape): array
    {
        $stored = $this->storedValue($markdown, $shape);
        $schema = new Schema([
            new Field('page_builder', Kind::Blocks, 'Page builder', sets: ['text' => new Set('Text', '', [new Field('heading', Kind::Text, 'Heading'), $this->previewField($shape)])]),
        ]);
        $data = ['page_builder' => [['type' => 'text', 'heading' => 'Winter care', 'copy' => $stored]]];

        return [(new PreviewMarkers)->mark($data, $schema)->data, $stored];
    }
}
