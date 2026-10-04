<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

/**
 * The revisit list's one setting: whether links to other sites are checked
 * once a week. Off by default; a site manager turns it on. Links to the
 * site's own entries are always checked, with no request (LinkTargets).
 */
final class RevisitOptions
{
    public function __construct(
        public readonly bool $externalLinks = false,
    ) {}
}
