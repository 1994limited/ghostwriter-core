<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

/**
 * A small schema and three entries to learn from, for the layout tests.
 */
final class Articles
{
    /**
     * Articles with a page builder: a hero, text, a quote that is the same
     * everywhere, and a spacer.
     */
    public static function schema(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('summary', Kind::LongText, 'Summary', 'Two sentences.'),
            new Field('image', Kind::Reference, 'Image', type: 'assets', files: true),
            new Field('tone', Kind::Choice, 'Tone', options: ['warm' => 'Warm', 'dry' => 'Dry']),
            new Field('body', Kind::Blocks, 'Body', sets: [
                'hero' => new Set('Hero', 'The top of the page', [
                    new Field('heading', Kind::Text, 'Heading', required: true),
                    new Field('background', Kind::Choice, 'Background', options: ['light' => 'Light', 'dark' => 'Dark']),
                    new Field('picture', Kind::Reference, 'Picture', files: true),
                ]),
                'text' => new Set('Text', '', [new Field('copy', Kind::RichText, 'Copy')]),
                'quote' => new Set('Quote', '', [new Field('quote', Kind::LongText, 'Quote'), new Field('person', Kind::Text, 'Person')]),
                'spacer' => new Set('Spacer', '', [new Field('height', Kind::Number, 'Height')]),
                'gallery' => new Set('Gallery', '', [new Field('images', Kind::Reference, 'Images', files: true)]),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function entry(int $id, string $title, array $values = []): EntryData
    {
        return new EntryData(['title' => $title] + $values + ['summary' => "About {$title}.", 'tone' => 'warm', 'body' => [
            ['id' => $id * 10 + 1, 'type' => 'hero', 'enabled' => true, 'heading' => $title, 'background' => 'dark'],
            ['id' => $id * 10 + 2, 'type' => 'text', 'enabled' => true, 'copy' => "<p>Words about {$title}.</p>"],
            ['id' => $id * 10 + 3, 'type' => 'quote', 'enabled' => true, 'quote' => 'Gardens take time.', 'person' => 'Priya'],
            ['id' => $id * 10 + 4, 'type' => 'spacer', 'enabled' => true, 'height' => 40],
        ]], $id);
    }

    /**
     * @return array<int, EntryData>
     */
    public static function entries(): array
    {
        return [self::entry(3, 'Orchards'), self::entry(2, 'Meadows'), self::entry(1, 'Walled gardens')];
    }
}
