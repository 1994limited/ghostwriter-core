<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;

/**
 * One entry as EntrySource hands it to the index: its reference, title
 * and edit URL, and the CheckContext the free checks read (the saved
 * entry's values; Craft: the canonical, not anyone's draft).
 */
final class EntrySnapshot
{
    public function __construct(
        public readonly EntryRef $ref,
        public readonly string $title,
        public readonly ?string $editUrl,
        public readonly CheckContext $context,
        public readonly bool $published = true,
    ) {}
}
