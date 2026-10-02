<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

/**
 * What goes on the asset with its licensed file: the credit line and
 * address, the licence type, any restrictions, and the ledger record's ID
 * (Statamic's `ghostwriter_stock` asset data). Never the title or alt
 * text: those are the editor's by now, and stay as they are.
 */
final class ReplaceMeta
{
    public function __construct(
        public readonly string $ledgerId,
        public readonly ?string $creditLine = null,
        public readonly ?string $creditUrl = null,
        public readonly ?string $licenceType = null,
        public readonly ?string $restrictions = null,
        public readonly bool $editorial = false,
    ) {}

    public static function for(StockImage $image): self
    {
        return new self($image->id, $image->creditLine, $image->creditUrl, $image->licenceType, $image->restrictions, $image->editorial);
    }
}
