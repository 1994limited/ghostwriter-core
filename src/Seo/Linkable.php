<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\IndexRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;
use Throwable;

/**
 * Whether a page may be offered as a link target (SEO layer §7.1, "Left
 * out"), from what the addon's link source reports, so the three CMSes
 * leave out alike. excluded() gives the reason, for the log:
 *
 * - unpublished: a draft, or disabled for the site (addons don't keep these rows);
 * - scheduled: before its `liveFrom` day; it's linkable that day without a save;
 * - expired: from its `liveUntil`;
 * - no-url: no route or URI format, or a redirect;
 * - noindex: an SEO addon or a field asks search engines not to index it;
 * - home: the home page (`/`);
 * - utility: the address's last segment is a utility page in any of core's
 *   languages (search, login, cart, thank-you, "suche", "merci"…).
 *
 * The page itself and pages the draft links to already are left out when
 * asked (LinkCandidates::rank()).
 */
final class Linkable
{
    public const UNPUBLISHED = 'unpublished';

    public const SCHEDULED = 'scheduled';

    public const EXPIRED = 'expired';

    public const NO_URL = 'no-url';

    public const NOINDEX = 'noindex';

    public const HOME = 'home';

    public const UTILITY = 'utility';

    /** @var array<string, true>|null */
    private static ?array $utility = null;

    /** Why a row can't be linked to on $now; null when it can. */
    public static function excluded(IndexRow $row, ?DateTimeImmutable $now = null): ?string
    {
        $now ??= new DateTimeImmutable;

        if (! $row->published) {
            return self::UNPUBLISHED;
        }

        if ($row->liveFrom !== null && ($from = self::date($row->liveFrom)) !== null && $from > $now) {
            return self::SCHEDULED;
        }

        if ($row->liveUntil !== null && ($until = self::date($row->liveUntil)) !== null && $until <= $now) {
            return self::EXPIRED;
        }

        $path = $row->path();

        if ($path === null) {
            return self::NO_URL;
        }

        if ($row->noindex) {
            return self::NOINDEX;
        }

        if ($path === '/') {
            return self::HOME;
        }

        return self::isUtility($path) ? self::UTILITY : null;
    }

    /**
     * Whether a row is worth keeping at all: published, with an address.
     * Scheduled, expired, noindex and utility rows are kept, so the reason
     * can be logged and a scheduled page is linkable on its day.
     */
    public static function keep(IndexRow $row): bool
    {
        return $row->published && $row->path() !== null;
    }

    /** Whether an address's last segment is a utility page's: "/shop/cart", "/de/danke". */
    public static function isUtility(string $url): bool
    {
        $path = IndexRow::pathOf($url) ?? '';
        $segments = explode('/', trim($path, '/'));
        $last = mb_strtolower(rawurldecode((string) end($segments)));
        $last = (string) preg_replace('/\.(html?|php)$/', '', $last);

        return $last !== '' && isset(self::utilitySlugs()[$last]);
    }

    /**
     * Every language's utility slugs: a German site has a /search page as
     * often as a /suche.
     *
     * @return array<string, true>
     */
    private static function utilitySlugs(): array
    {
        if (self::$utility === null) {
            $slugs = [];

            foreach (Phrases::LANGUAGES as $language) {
                foreach (Phrases::for($language)->utilitySlugs ?? [] as $slug) {
                    $slugs[$slug] = true;
                }
            }

            self::$utility = $slugs;
        }

        return self::$utility;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
