<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;

/**
 * What layouts need to know about where a piece is going: its schema, the
 * group's pattern and the entries it was found from (newest first, as
 * PatternFinder::find() took them), the kind's own defaults, and the ids
 * of the examples the writer was shown (for entry sources), and how its
 * template prints headings (Seo\RenderProfile::resolve(); null for the
 * default: the title is the H1).
 */
final class LayoutContext
{
    /**
     * @param  array<int, EntryData>  $entries
     * @param  array<string, mixed>  $defaults
     * @param  array<int, int|string|null>  $exampleIds
     */
    public function __construct(
        public readonly Schema $schema,
        public readonly ?Pattern $pattern = null,
        public readonly array $entries = [],
        public readonly array $defaults = [],
        public readonly array $exampleIds = [],
        public readonly ?RenderProfile $profile = null,
    ) {}
}
