<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\SentenceFit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fit check after the model: a replacement put in place of its quote
 * must leave whole sentences, with no model.
 */
final class SentenceFitTest extends TestCase
{
    /**
     * block, quote, replacement, the problem (null: it fits), may be empty.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string, 4?: bool}>
     */
    public static function cases(): iterable
    {
        $eyebrow = 'New for 2023: winter care visits';
        $prices = 'Our prices, as of 2023, start at £450. Book a visit.';

        yield 'a whole value rewritten' => [$eyebrow, $eyebrow, 'Winter care visits', null];
        yield 'the dated words taken out' => [$eyebrow, $eyebrow, 'Our winter care visits', null];
        yield 'a capital lost at the start' => [$eyebrow, $eyebrow, 'winter care visits', 'start'];
        yield 'a digit may start it' => [$eyebrow, $eyebrow, '24 winter care visits', null];
        yield 'a quote mark may start it' => [$eyebrow, $eyebrow, '“Winter” care visits', null];
        yield 'a sentence keeps its full stop' => [$prices, 'Our prices, as of 2023, start at £450.', 'Our prices start at £450.', null];
        yield 'a sentence loses its full stop' => [$prices, 'Our prices, as of 2023, start at £450.', 'Our prices start at £450', 'end'];
        yield 'the second sentence starts after a full stop' => [$prices, 'Book a visit.', 'book a visit.', 'start'];
        yield 'mid-sentence lower case is right' => [$prices, 'as of 2023', 'for now', null];
        yield 'mid-sentence it may not end the sentence' => [$prices, 'start at £450', 'start at £450.', 'end'];
        yield 'a doubled word before' => ['We will first of all come out and visit.', 'come out and visit', 'all visit', 'doubled'];
        yield 'a doubled word after' => ['We will come out and visit the garden.', 'come out and visit', 'come and visit the', 'doubled'];
        yield 'a word the text already doubled' => ['He said that that was fine.', 'that was', 'that is', null];
        yield 'nothing in the middle of a sentence' => [$prices, 'as of 2023', '', 'empty'];
        yield 'a duplicate may shrink to nothing' => [$prices, 'as of 2023', '', null, true];
        yield 'a whole sentence may go' => [$prices, 'Book a visit.', '', null];
        yield 'markdown is read as text' => [$eyebrow, $eyebrow, '**Winter** care visits', null];
    }

    #[DataProvider('cases')]
    public function test_the_fit(string $block, string $quote, string $replacement, ?string $problem, bool $mayBeEmpty = false): void
    {
        $offset = mb_strpos($block, $quote);
        $this->assertNotFalse($offset);

        $this->assertSame($problem, SentenceFit::problem($block, $offset, mb_strlen($quote), $replacement, $mayBeEmpty));
        $this->assertSame($problem === null, SentenceFit::fits($block, $offset, mb_strlen($quote), $replacement, $mayBeEmpty));
    }
}
