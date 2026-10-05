<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;

/**
 * Whether Ghostwriter may write an SEO value, or only suggest one (SEO
 * layer §9.4, decisions 11 and 12). A person's text is never replaced
 * without asking:
 *
 * | The field holds                                                    | Action  |
 * |--------------------------------------------------------------------|---------|
 * | Nothing (an empty value of the page's own)                         | Write   |
 * | A value Ghostwriter wrote, unchanged since (SeoProvenance)          | Write   |
 * | A value anyone else wrote, or Ghostwriter's changed by a person    | Suggest |
 * | Inherited (another field, a default), text inside the range        | Leave   |
 * | Inherited, text empty or out of range                              | Suggest |
 * | A template core can evaluate nowhere, where the entry can override | new entry: Write the description, Leave the title; existing: Suggest |
 * | A template the entry can't override, switched off, not writable    | Leave   |
 *
 * The SEO title has one more rule (decision 12): it is only given text of
 * its own when the page title won't do (MetaRange::pageTitleTooLong()).
 * That is title(); decide() says only what may be done to the field.
 */
final class MetaPolicy
{
    public function decide(SeoField $field, SeoProvenance $provenance, bool $newEntry, ?MetaRange $range = null): MetaAction
    {
        $range ??= MetaRange::of($field);

        if ($field->source === SeoSource::Disabled) {
            return MetaAction::Leave;
        }

        if ($field->source === SeoSource::Template) {
            if (! $field->writable) {
                return MetaAction::Leave;
            }

            return $newEntry ? ($field->role === SeoField::DESCRIPTION ? MetaAction::Write : MetaAction::Leave) : MetaAction::Suggest;
        }

        if ($field->inherited()) {
            return $range->fits($field->text) || ($field->role === SeoField::TITLE && ! $range->tooLong($field->text) && trim((string) $field->text) !== '')
                ? MetaAction::Leave
                : ($field->writable ? MetaAction::Suggest : MetaAction::Leave);
        }

        if (! $field->writable) {
            return MetaAction::Leave;
        }

        if ($field->isEmpty() || $field->text === null) {
            return MetaAction::Write;
        }

        return $provenance->owns($field->role, $field->text) ? MetaAction::Write : MetaAction::Suggest;
    }

    /**
     * Whether an editor can give the page text of its own in this field
     * (the Search section's "Give it its own"), whatever Ghostwriter would
     * write by itself: the field takes a custom value, and isn't switched
     * off. Decision 12 governs only what Ghostwriter writes unasked.
     */
    public function ownable(SeoField $field): bool
    {
        return $field->writable && $field->source !== SeoSource::Disabled;
    }

    /**
     * Whether the page gets an SEO title of its own: only when the page
     * title is too long for the `<title>` once the site name is added
     * (decision 12; phase 2 adds a missing keyphrase), or the editor gave
     * it one. Otherwise the field keeps inheriting the page title, which
     * is what every SEO addon does by default.
     */
    public function wantsTitle(string $pageTitle, MetaRange $range): bool
    {
        return trim($pageTitle) !== '' && $range->pageTitleTooLong($pageTitle);
    }
}
