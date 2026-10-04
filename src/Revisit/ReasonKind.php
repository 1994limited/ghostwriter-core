<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

/** Why an entry is on the Content to revisit list. */
enum ReasonKind: string
{
    case Age = 'age';
    case PastYear = 'past-year';
    case RelativeTime = 'relative-time';
    case ClosingDate = 'closing-date';
    case BrokenLink = 'broken-link';
    case ExternalLink = 'external-link';
    case MissingAlt = 'missing-alt';
    case EmptyField = 'empty-field';
    case Leftover = 'leftover';
    case SeoLength = 'seo-length';
    case StatedCount = 'stated-count';

    /** Whether time alone changes it: age, past years and relative time weigh less in dated groups. */
    public function isTimely(): bool
    {
        return in_array($this, [self::Age, self::PastYear, self::RelativeTime], true);
    }
}
