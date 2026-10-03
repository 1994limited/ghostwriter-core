<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Anchor;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shared cases every port of QuoteFinder runs (resources/anchor/quote-cases.json). */
final class QuoteCasesTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $file = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/anchor/quote-cases.json'), true);

        foreach ($file['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_the_shared_case(array $case): void
    {
        $match = (new QuoteFinder)->find(TextQuote::fromArray($case['quote']), $case['text'], $case['occurrence'] ?? null, $case['markdown'] ?? false);

        if ($case['expect'] === null) {
            $this->assertNull($match, $match === null ? '' : 'Found: '.$match->text($case['text']));

            return;
        }

        $this->assertNotNull($match);
        $this->assertSame($case['expect'], [
            'offset' => $match->offset,
            'length' => $match->length,
            'text' => $match->text($case['text']),
            'occurrence' => $match->occurrence,
            'fuzzy' => $match->fuzzy,
        ]);
    }
}
