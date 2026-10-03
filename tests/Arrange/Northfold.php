<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

/**
 * A services page, a journal post and a plain record, as the three schema
 * shapes layouts work on: a page builder (with nested blocks), one rich
 * text field, and plain fields only.
 */
final class Northfold
{
    public const BODY = "Winter is when a garden is set up for the year.\n\n## The visits\n\n**November: Cut back.** Prune the shrubs that need it.\n\n**January: Feed.** Mulch the beds and [[ask: what else in January]].\n\n## Who it suits\n\nGardens with mixed borders. [Talk to us](#gw-link:contact-page)\n\n- Lawns\n- Gravel\n\n> We used to clear everything in October.";

    public static function blocks(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('summary', Kind::LongText, 'Summary'),
            new Field('page_builder', Kind::Blocks, 'Page builder', sets: [
                'hero' => new Set('Hero', '', [new Field('heading', Kind::Text, 'Heading', required: true), new Field('subheading', Kind::Text), new Field('image', Kind::Reference, 'Image', files: true), new Field('background', Kind::Choice, options: ['green' => 'Green', 'white' => 'White'])]),
                'text' => new Set('Text', '', [new Field('body', Kind::RichText, 'Body')]),
                'cards' => new Set('Cards', '', [new Field('cards', Kind::Rows, 'Cards', fields: [new Field('heading', Kind::Text), new Field('body', Kind::LongText)])]),
                'faq' => new Set('FAQ', '', [new Field('questions', Kind::Rows, 'Questions', fields: [new Field('question', Kind::Text), new Field('answer', Kind::LongText)])]),
                'stats' => new Set('Stats', '', [new Field('items', Kind::Rows, 'Items', fields: [new Field('value', Kind::Text), new Field('label', Kind::Text)])]),
                'quote' => new Set('Quote', '', [new Field('text', Kind::LongText), new Field('attribution', Kind::Text)]),
                'ticks' => new Set('Ticks', '', [new Field('items', Kind::List)]),
                'cta' => new Set('Call to action', '', [new Field('heading', Kind::Text), new Field('button', Kind::Text)]),
                'spacer' => new Set('Spacer', '', [new Field('size', Kind::Number)]),
                'section' => new Set('Section', '', [
                    new Field('heading', Kind::Text),
                    new Field('children', Kind::Blocks, engine: Field::CHILDREN, sets: [
                        'text' => new Set('Text', '', [new Field('body', Kind::RichText)]),
                        'card' => new Set('Card', '', [new Field('heading', Kind::Text), new Field('body', Kind::LongText)]),
                    ]),
                ]),
            ], meta: ['max' => 12]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function blocksDraft(): array
    {
        return [
            'title' => 'Winter garden care',
            'summary' => 'Four visits between November and February.',
            'page_builder' => [
                ['type' => 'hero', 'heading' => 'Winter garden care', 'subheading' => 'Set the garden up for spring.', 'image' => 'assets::garden.jpg', 'background' => 'green'],
                ['type' => 'text', 'body' => self::BODY],
                ['type' => 'spacer', 'size' => 20],
                ['type' => 'cta', 'heading' => 'Book a winter visit', 'button' => 'Book now'],
            ],
        ];
    }

    public static function richText(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('excerpt', Kind::LongText, 'Excerpt'),
            new Field('body', Kind::RichText, 'Body', type: 'bard', sets: ['pull_quote' => new Set('Pull quote', '', [new Field('text', Kind::LongText)])]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function richTextDraft(): array
    {
        return ['title' => 'Winter garden care', 'body' => self::BODY];
    }

    public static function plain(): Schema
    {
        return new Schema([
            new Field('title', Kind::Text, 'Title', required: true),
            new Field('intro', Kind::LongText, 'Intro'),
            new Field('details', Kind::LongText, 'Details'),
            new Field('tags', Kind::List, 'Tags'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function plainDraft(): array
    {
        return ['title' => 'Winter garden care', 'details' => "Four visits between November and February.\n\nWe prune, mulch and protect.", 'tags' => ['winter', 'care']];
    }

    public static function extras(): Extras
    {
        return Extras::fromArray([
            ['id' => 'x1', 'kind' => 'stats', 'items' => [
                ['id' => 'x1.1', 'text' => '4 visits a winter', 'parts' => ['value' => '4', 'label' => 'visits a winter'], 'source' => ['kind' => 'draft', 'quote' => 'Four visits between November and February']],
                ['id' => 'x1.2', 'text' => 'From [[ask: price per visit]] a visit', 'askHints' => ['price per visit']],
            ]],
            ['id' => 'x2', 'kind' => 'faq', 'items' => [
                ['id' => 'x2.1', 'text' => 'Four times, between November and February.', 'parts' => ['question' => 'How often do you visit?'], 'source' => ['kind' => 'draft', 'quote' => 'Four visits between November and February']],
            ]],
            ['id' => 'x3', 'kind' => 'intro', 'items' => [
                ['id' => 'x3.1', 'text' => 'Four visits that set a garden up for spring.', 'source' => ['kind' => 'draft', 'quote' => 'Four visits between November and February']],
            ]],
            ['id' => 'x4', 'kind' => 'stats', 'items' => [
                ['id' => 'x4.1', 'text' => 'Trusted by 500 gardens'],
            ]],
        ]);
    }
}
