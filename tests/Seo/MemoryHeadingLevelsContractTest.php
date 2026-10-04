<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\HeadingLevelsContractTest;

/**
 * The heading levels contract on core's own field specs, with markdown
 * stored as it is (a Markdown field's apply path).
 */
final class MemoryHeadingLevelsContractTest extends HeadingLevelsContractTest
{
    protected function twoLevelField(): Field
    {
        return Field::fromSpec(['handle' => 'body', 'kind' => 'richtext', 'headings' => [2, 3]]);
    }

    protected function noHeadingField(): Field
    {
        return Field::fromSpec(['handle' => 'body', 'kind' => 'richtext', 'headings' => []]);
    }

    protected function stored(string $markdown, Field $field): string
    {
        return $markdown;
    }
}
