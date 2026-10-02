<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Gaps\RoundTrip;

use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseStyle;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

/**
 * Core's share of every addon's apply path: the draft's markdown through
 * EntryBuilder and the house style's dressing, then read back with the
 * dialect, inside a block as the page builder holds it.
 */
trait BuilderRoundTrip
{
    abstract protected function dialect(): RichTextDialect;

    protected function richField(): Field
    {
        return new Field('copy', Kind::RichText);
    }

    protected function roundTripMarkers(string $markdown, string $shape): string
    {
        $field = match ($shape) {
            'rich' => $this->richField(),
            'markdown' => new Field('copy', Kind::LongText, type: 'markdown'),
            'plain' => new Field('copy', Kind::Text),
        };

        $schema = new Schema([new Field('body', Kind::Blocks, sets: ['text' => new Set('Text', '', [$field])])]);
        $built = (new EntryBuilder(richText: $this->dialect()))->build(['body' => [['type' => 'text', 'copy' => $markdown]]], $schema);
        $data = (new HouseStyle(richText: $this->dialect()))->apply($built->data, $schema, new HouseRules)->data;
        $value = $data['body'][0]['copy'] ?? null;

        return $shape === 'rich' ? (string) $this->dialect()->toMarkdown($value, $field) : (is_string($value) ? $value : '');
    }
}
