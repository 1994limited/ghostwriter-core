<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;

/**
 * The links in an entry, split in two: what its links to the site's own
 * entries and assets hold (`entry::abc`, `{entry:12@1:url}`), and its
 * links to other sites (http and https addresses on other hosts). Inline
 * links in text and link fields' values both count. `#gw-link:`
 * sentinels, anchors, `mailto:` and `tel:` are neither.
 */
final class Links
{
    private const INLINE = '/\[[^\[\]\n]*\]\(\s*<?([^\s)>]+)>?(?:\s+"[^"\n]*")?\s*\)/u';

    /**
     * @param  array<int, string>  $ownHosts  The site's own hosts ("northfold.co.uk"); "www." is ignored.
     * @return array{internal: list<string>, external: list<string>}
     */
    public static function in(CheckContext $context, array $ownHosts = []): array
    {
        return self::of($context->gaps, $ownHosts);
    }

    /**
     * The same for Finish this page's context: what FewLinks counts.
     *
     * @param  array<int, string>  $ownHosts
     * @return array{internal: list<string>, external: list<string>}
     */
    public static function of(GapContext $context, array $ownHosts = []): array
    {
        $own = array_map(fn (string $host) => self::host($host), $ownHosts);
        $internal = [];
        $external = [];
        $add = function (string $target) use (&$internal, &$external, $own): void {
            $target = trim($target);

            if ($target === '' || Markers::isLinkSentinel($target) || preg_match('/^(?:#|mailto:|tel:|javascript:)/i', $target) === 1) {
                return;
            }

            if (preg_match('/^https?:\/\//i', $target) === 1) {
                $host = self::host((string) parse_url($target, PHP_URL_HOST));

                if ($host !== '' && ! in_array($host, $own, true)) {
                    $external[$target] = true;

                    return;
                }
            }

            $internal[$target] = true;
        };

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ($context->links->holdsLinks($visit->field)) {
                $values = is_array($visit->value) ? $visit->value : [$visit->value];
                array_walk_recursive($values, function ($item) use ($add): void {
                    if (is_string($item) && (str_contains($item, '::') || str_contains($item, '{') || preg_match('/^(?:https?:\/\/|\/)/i', $item) === 1)) {
                        $add($item);
                    }
                });

                continue;
            }

            $text = Walk::text($visit, $context->richText);

            if ($text !== null && preg_match_all(self::INLINE, $text, $matches) > 0) {
                foreach ($matches[1] as $href) {
                    $add($href);
                }
            }
        }

        return ['internal' => array_keys($internal), 'external' => array_keys($external)];
    }

    /** A host as compared: lower-cased, without "www.". */
    public static function host(string $host): string
    {
        $host = strtolower(trim($host));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
