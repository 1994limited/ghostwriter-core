<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/** Rich text stored as markdown, as Statamic's markdown fields keep it. */
final class MarkdownDialect implements RichTextDialect
{
    public function fromMarkdown(string $markdown, Field $field): mixed
    {
        return $markdown;
    }

    public function toMarkdown(mixed $value, Field $field): ?string
    {
        return is_string($value) ? $value : null;
    }

    public function isWritten(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    public function shapes(array $samples): array
    {
        return [];
    }

    public function dress(mixed $value, array $shapes): mixed
    {
        return $value;
    }
}
