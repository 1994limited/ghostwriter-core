<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use InvalidArgumentException;

/**
 * What a photo library can do and what its terms allow, read by the image
 * dialog, the ledger and the ranker:
 *
 * - `free`: no licence step. "Use this" saves the final file, and the
 *   ledger marks it licensed at once.
 * - `needsOAuth`: licensing (or searching) needs a connected customer
 *   account, not just an app key.
 * - `quotes`: what is known of the cost before buying. QUOTES_EXACT (a
 *   cash price), QUOTES_BALANCE (counts against a download limit: "1
 *   download"), QUOTES_CREDITS (iStock's credits per licence type) or
 *   QUOTES_NONE.
 * - `previewKeepDays`: how long a comp may be kept, from the provider's
 *   terms (Getty and iStock 30, Adobe 90). Null for a free library.
 * - `previewStorage`: STORAGE_PRIVATE (kept in Ghostwriter's own storage,
 *   served to signed-in control panel users) or STORAGE_NONE (only the
 *   provider's preview address is shown; nothing is stored). Null for a
 *   free library.
 * - `mayRank`: thumbnails and metadata may be shown to a model (the
 *   `photo-picker` agent). False for paid libraries, whose licences forbid
 *   using content or its metadata for AI. PhotoRanker never shows a model
 *   a photo from a library without it.
 * - `noModelInput`: no asset from this library (a comp, a stand-in or the
 *   licensed file) may be sent to any model, for any feature: not as a
 *   reference image, not as a sample to describe. True for paid
 *   libraries. Stricter than `mayRank`, which is about search results.
 * - `termsCheckedAt`: when the adapter's reading of the provider's terms
 *   was last checked, as Y-m-d.
 * - `editorial`: search can return editorial-only images, so results
 *   carry `editorial` and `restrictions`.
 * - `creditRequired`: a credit line must be shown wherever the image is
 *   used.
 * - `sandbox`: the provider has a test environment the adapter can use.
 */
final class Capabilities
{
    public const QUOTES_EXACT = 'exact';

    public const QUOTES_BALANCE = 'balance';

    public const QUOTES_CREDITS = 'credits';

    public const QUOTES_NONE = 'none';

    public const STORAGE_PRIVATE = 'private';

    public const STORAGE_NONE = 'none';

    public function __construct(
        public readonly bool $free,
        public readonly bool $mayRank,
        public readonly bool $needsOAuth = false,
        public readonly string $quotes = self::QUOTES_NONE,
        public readonly ?int $previewKeepDays = null,
        public readonly ?string $previewStorage = null,
        public readonly ?string $termsCheckedAt = null,
        public readonly bool $editorial = false,
        public readonly bool $creditRequired = false,
        public readonly bool $sandbox = false,
        public readonly bool $noModelInput = false,
    ) {
        if (! in_array($quotes, [self::QUOTES_EXACT, self::QUOTES_BALANCE, self::QUOTES_CREDITS, self::QUOTES_NONE], true)) {
            throw new InvalidArgumentException("Unknown quotes capability \"{$quotes}\".");
        }

        if ($previewStorage !== null && ! in_array($previewStorage, [self::STORAGE_PRIVATE, self::STORAGE_NONE], true)) {
            throw new InvalidArgumentException("Unknown preview storage \"{$previewStorage}\".");
        }

        if ($termsCheckedAt !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $termsCheckedAt)) {
            throw new InvalidArgumentException('termsCheckedAt is a date: Y-m-d.');
        }
    }

    /**
     * A free library: nothing to buy and nothing to preview. Its photos
     * may be judged by a model unless its terms say otherwise ($mayRank).
     */
    public static function free(?string $termsCheckedAt = null, bool $creditRequired = false, bool $mayRank = true): self
    {
        return new self(free: true, mayRank: $mayRank, termsCheckedAt: $termsCheckedAt, creditRequired: $creditRequired);
    }

    /**
     * A library that sells licences. Its photos are never judged by a
     * model unless $mayRank says its terms allow it, and its assets never
     * go to a model at all unless $noModelInput is turned off.
     */
    public static function paid(
        string $quotes,
        int $previewKeepDays,
        string $previewStorage = self::STORAGE_PRIVATE,
        bool $needsOAuth = false,
        bool $mayRank = false,
        ?string $termsCheckedAt = null,
        bool $editorial = false,
        bool $creditRequired = false,
        bool $sandbox = false,
        bool $noModelInput = true,
    ): self {
        return new self(false, $mayRank, $needsOAuth, $quotes, $previewKeepDays, $previewStorage, $termsCheckedAt, $editorial, $creditRequired, $sandbox, $noModelInput);
    }

    /**
     * For JSON, such as the image dialog's list of libraries.
     *
     * @return array{free: bool, may_rank: bool, needs_oauth: bool, quotes: string, preview_keep_days: ?int, preview_storage: ?string, terms_checked_at: ?string, editorial: bool, credit_required: bool, sandbox: bool, no_model_input: bool}
     */
    public function toArray(): array
    {
        return [
            'free' => $this->free,
            'may_rank' => $this->mayRank,
            'needs_oauth' => $this->needsOAuth,
            'quotes' => $this->quotes,
            'preview_keep_days' => $this->previewKeepDays,
            'preview_storage' => $this->previewStorage,
            'terms_checked_at' => $this->termsCheckedAt,
            'editorial' => $this->editorial,
            'credit_required' => $this->creditRequired,
            'sandbox' => $this->sandbox,
            'no_model_input' => $this->noModelInput,
        ];
    }
}
