<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * The kinds of thing an editor must finish before a page goes live. The
 * first ones are found by marker or by value, for nothing; OffStyleImage is
 * the only one a model judges, and only when asked.
 */
enum GapKind: string
{
    /** `[[ask: …]]` in text. */
    case Ask = 'ask';

    /**
     * `[[check: 3 areas | from: …]]` in text: a count Ghostwriter made
     * from a list the editor gave, to confirm before the page goes live.
     */
    case Check = 'check';

    /** A fact meant for a field that can't hold text, which is empty. */
    case AskValue = 'ask-value';

    /** `#gw-link:` inline or in a link field, or the legacy example.com. */
    case LinkToChoose = 'link';

    /** An expected or required link field with nothing in it. */
    case LinkEmpty = 'link-empty';

    /** A link to an entry that doesn't exist. */
    case LinkBroken = 'link-broken';

    case ImagePlaceholder = 'image-placeholder';

    case ImageEmpty = 'image-empty';

    /** A stock photo previewed but not licensed. */
    case StockPreview = 'stock-preview';

    /**
     * A required field left empty. No detector core ships finds these any
     * more (the CMS's own validation does, on save); kept for the gaps an
     * older session or job carries.
     */
    case Required = 'required';

    case Expected = 'expected';

    /** A vocabulary placeholder (`[[item]]`) left in text. */
    case LeftoverToken = 'leftover-token';

    /** `TBC`, `[insert …]`, `lorem ipsum`. */
    case PlaceholderText = 'placeholder-text';

    case MissingAlt = 'missing-alt';

    case SeoLength = 'seo-length';

    case OffStyleImage = 'off-style-image';

    /** How much it matters, unless a detector says otherwise. */
    public function severity(): Severity
    {
        return match ($this) {
            self::Ask, self::AskValue, self::Check, self::LinkToChoose, self::LinkBroken, self::ImagePlaceholder, self::StockPreview, self::LeftoverToken => Severity::Blocks,
            self::ImageEmpty => Severity::Prompt,
            self::LinkEmpty, self::Required => Severity::Required,
            default => Severity::Suggestion,
        };
    }

    /** The key of the mark's short speech label ("Fill this in"). */
    public function speech(): string
    {
        return 'gaps.speech.'.$this->value;
    }

    /**
     * Whether it says only that a field is empty, which a more specific gap
     * on the same field (a fact asked for, an image still to choose) says
     * better.
     */
    public function isGeneral(): bool
    {
        return in_array($this, [self::Required, self::Expected], true);
    }
}
