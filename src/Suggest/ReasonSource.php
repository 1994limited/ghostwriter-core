<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/** Where a suggestion's reason comes from, shown under it ("Voice guide: “What this voice never does”"). */
enum ReasonSource: string
{
    /** A heading of the voice guide, quoted exactly. */
    case VoiceGuide = 'voice-guide';

    /** The kind's guidance or a checklist item. */
    case Kind = 'kind';

    /** The house's learned rules: heading levels, link habits. */
    case House = 'house';

    /** A free check: "Found without AI". */
    case Check = 'check';

    /** Another entry of the site, from the digest. */
    case SiteEntry = 'site-entry';

    /** "Checked against the image". */
    case Image = 'image';

    /** "Facts only come from you". */
    case Editor = 'editor';

    /** "General writing advice": the honest label when nothing above applies. */
    case General = 'general';
}
