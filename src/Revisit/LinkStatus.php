<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

/** What checking a link to another site found. */
enum LinkStatus: string
{
    /** It answered with a page (2xx, or a redirect to one). */
    case Ok = 'ok';

    /** It's gone: 404 or 410, or the site's name no longer exists. */
    case Broken = 'broken';

    /** It couldn't be told: a timeout, a refusal, a server error, too many requests. Never shown. */
    case Unknown = 'unknown';
}
