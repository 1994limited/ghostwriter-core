<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoFields;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * The draft's search title and description into the entry's SEO fields on
 * "Use this draft" (SEO layer §9.3, §9.4), in each addon's build path, so
 * they land in the form or the provisional draft and nothing is saved
 * until the editor saves:
 *
 * - each text the session has (SeoState::$meta) goes where MetaPolicy
 *   says Ghostwriter may write it (SeoMeta::action()), through the addon's
 *   SeoWriter, in its field's own shape;
 * - where it says only Suggest (a person's text, an inherited one that
 *   doesn't fit), the text is kept for Finish this page instead, unless
 *   the editor chose "Use this" in the Search section;
 * - where it says Leave, nothing happens.
 *
 * What Ghostwriter wrote, and nobody edited in the Search section, is
 * returned as SeoProvenance for the session (SeoState::withWritten()), so
 * the text is still known as its own later.
 */
final class SearchFields
{
    public function __construct(
        private readonly SeoFields $fields,
        private readonly SeoWriter $writer,
        private readonly SeoMeta $meta = new SeoMeta,
    ) {}

    /**
     * @param  array<string, mixed>  $values  The entry's values as the form or draft holds them, the draft already in.
     * @param  EntryData  $entry  The same values as SeoFields reads them, with the entry's group and site.
     * @param  SeoProvenance  $provenance  What Ghostwriter wrote into this entry before (its earlier sessions).
     */
    public function apply(array $values, Schema $schema, EntryData $entry, SeoState $state, bool $newEntry, SeoProvenance $provenance = new SeoProvenance): SearchApplied
    {
        $found = [SeoField::TITLE => null, SeoField::DESCRIPTION => null];

        foreach ($this->fields->in($schema, $entry) as $field) {
            $found[$field->role] ??= $field;
        }

        $format = $this->fields->titleFormat($schema, $entry);
        $known = $provenance->merge($state->written);
        $written = new SeoProvenance;
        $actions = [];
        $suggested = [];

        foreach ([SeoField::TITLE, SeoField::DESCRIPTION] as $role) {
            $text = $state->meta->text($role);
            $field = $found[$role];

            if ($text === '' || $field === null) {
                continue;
            }

            $action = $this->meta->action($field, $newEntry, $known, $this->meta->range($field, $role, $format), $state->meta->edited($role)) ?? MetaAction::Leave;
            $actions[$role] = $action;

            if ($action === MetaAction::Write || ($action === MetaAction::Suggest && $state->meta->uses($role))) {
                $values = $this->writer->write($values, $field, $text);

                if (! $state->meta->edited($role)) {
                    $written = $written->with($role, $text);
                }
            } elseif ($action === MetaAction::Suggest) {
                $suggested[$role] = $text;
            }
        }

        return new SearchApplied($values, $written, $actions, $suggested);
    }
}
