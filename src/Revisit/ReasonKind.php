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

    // The SEO layer (§13.3): never more than Priority::SEO_CAP together.

    /** An SEO description field the page prints nothing for. */
    case SeoMissing = 'seo-missing';

    /** 300 words or more and no link to the site's own pages. */
    case FewLinks = 'few-links';

    /** HeadingFixer would move a heading's level (a skipped level, a body H1 under the template's). */
    case HeadingLevels = 'heading-levels';

    /** Another published page for the same search (phase 2: not found yet). */
    case Competing = 'competing';

    /** Long paragraphs or a wall of text (phase 2: not found yet). */
    case Readability = 'readability';

    /** Whether it's about how the page does in search: these share one cap (Priority::SEO_CAP). */
    public function isSeo(): bool
    {
        return in_array($this, [self::SeoLength, self::SeoMissing, self::FewLinks, self::HeadingLevels, self::Competing, self::Readability], true);
    }

    /** Whether time alone changes it: age, past years and relative time weigh less in dated groups. */
    public function isTimely(): bool
    {
        return in_array($this, [self::Age, self::PastYear, self::RelativeTime], true);
    }
}
