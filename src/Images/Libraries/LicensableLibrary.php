<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * A library that sells licences, always from the customer's own account
 * with the customer's own key. Licensing is done once per ledger record
 * (StockImages::license()), and a call whose outcome is unknown is never
 * made again: findLicences() settles it.
 */
interface LicensableLibrary extends PreviewableLibrary
{
    /** The largest licensed original that will be downloaded. */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /**
     * Connected as whom, and what the account can buy.
     *
     * @throws NotConnected
     */
    public function account(): Account;

    /**
     * The licence options this account can buy for this photo, priced as
     * well as the library can before buying.
     *
     * @return array<int, Quote>
     *
     * @throws NotConnected
     * @throws PhotoUnavailable
     */
    public function quotes(string $id): array;

    /**
     * Buy the licence, once. `$key` is the ledger record's ID: the
     * purchase's idempotency key on our side, and sent as the client
     * reference where the provider takes one. Never retried: the
     * transport must not replay it (Downloader::post()).
     *
     * @throws LicensingUncertain when it may have charged but the outcome is unknown.
     * @throws InsufficientBalance
     * @throws QuoteChanged
     * @throws LicenceRefused
     * @throws NotConnected
     */
    public function license(string $id, Quote $quote, string $key, string $licensedBy): Licence;

    /**
     * The licensed file, byte for byte as the provider delivers it (never
     * re-encoded: the licences require its embedded copyright and IDs to
     * stay), at most MAX_BYTES. May be called again later.
     *
     * @throws PhotoUnavailable
     */
    public function download(Licence $licence): PhotoFile;

    /**
     * The licences the account holds for this photo, for reconciling a
     * licence call whose outcome was unknown.
     *
     * @return array<int, Licence>
     *
     * @throws PhotoUnavailable
     */
    public function findLicences(string $id): array;
}
