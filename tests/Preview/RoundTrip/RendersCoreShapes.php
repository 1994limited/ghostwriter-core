<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Preview\RoundTrip;

use League\CommonMark\CommonMarkConverter;
use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseStyle;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

/**
 * Core's share of the apply path (EntryBuilder and the house style) for
 * storing, and the plain ways a template prints markdown and text.
 */
trait RendersCoreShapes
{
    abstract protected function dialect(): RichTextDialect;

    abstract protected function renderRich(mixed $stored): string;

    protected function storedValue(string $markdown, string $shape): mixed
    {
        $field = $this->previewField($shape);
        $schema = new Schema([new Field('body', Kind::Blocks, sets: ['text' => new Set('Text', '', [$field])])]);
        $built = (new EntryBuilder(richText: $this->dialect()))->build(['body' => [['type' => 'text', 'copy' => $markdown]]], $schema);

        return (new HouseStyle(richText: $this->dialect()))->apply($built->data, $schema, new HouseRules)->data['body'][0]['copy'] ?? null;
    }

    protected function renderValue(mixed $stored, string $shape): string
    {
        return match ($shape) {
            'rich' => $this->renderRich($stored),
            'markdown' => (string) (new CommonMarkConverter)->convert(is_string($stored) ? $stored : ''),
            'plain' => '<h2>'.htmlspecialchars(is_string($stored) ? $stored : '', ENT_QUOTES).'</h2>',
        };
    }
}
