<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * What Ghostwriter does with one SEO value (MetaPolicy, SEO layer §9.4).
 */
enum MetaAction: string
{
    /** Put Ghostwriter's text in: the value is empty, or Ghostwriter's own and unchanged. */
    case Write = 'write';

    /** Leave the value, and offer Ghostwriter's text beside it (a person's text, or an inherited one that doesn't fit). */
    case Suggest = 'suggest';

    /** Leave it alone: it inherits text that fits, comes from a template, or is switched off. */
    case Leave = 'leave';
}
