<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\Articles;
use PHPUnit\Framework\TestCase;

abstract class LayoutTestCase extends TestCase
{
    protected static function articles(): Schema
    {
        return Articles::schema();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected static function entry(int $id, string $title, array $values = []): EntryData
    {
        return Articles::entry($id, $title, $values);
    }

    /**
     * @return array<int, EntryData>
     */
    protected static function entries(): array
    {
        return Articles::entries();
    }
}
