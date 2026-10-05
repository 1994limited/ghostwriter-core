<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use Throwable;

/**
 * The Text tab's Search section (SEO layer §9.5, decision 21), as data
 * each addon draws in its own panel: one row for the SEO title, one for the
 * description and one for the address, each null where the page has none.
 *
 * Every row has the text to show and whether it can be edited, the count
 * against the field's limit with the range it should be in (the count
 * turns amber outside it), what Ghostwriter will do with it on "Use this
 * draft" (`action`), and one line saying so (`note`, a Gaps\Message key
 * under `seo.search.*`). Nothing here calls a model or writes anything.
 *
 * - **title:** the page's own SEO title when it has one (`own`); otherwise
 *   it uses the page title (`pageTitle`), with why, and "Give it its own".
 * - **description:** Ghostwriter's (or the editor's) text; where the
 *   entry's own stays (`action: suggest`), its text is `current` and
 *   Ghostwriter's is offered with "Use this"; where the field inherits text
 *   that fits, that text is `current` and nothing is written.
 * - **address:** the slug, editable only where it can be set (a new or
 *   never-published entry), after `base`.
 */
final class SearchSection
{
    public function __construct(private readonly SeoMeta $meta = new SeoMeta) {}

    /**
     * @return array{title: array<string, mixed>|null, description: array<string, mixed>|null, address: array<string, mixed>|null, fields: bool}
     */
    public function of(Session $session, MetaContext $context): array
    {
        $data = [];

        try {
            $data = $session->draft !== null && trim($session->draft) !== '' ? Draft::parse($session->draft)->data : [];
        } catch (Throwable) {
            $data = [];
        }

        $state = SeoState::of($session);
        $meta = $state->meta;
        $fields = $this->meta->fields($context, $data);
        $provenance = $context->provenance->merge($state->written);
        $pageTitle = trim(is_scalar($data['title'] ?? null) ? (string) $data['title'] : '');
        $rows = [];

        foreach ([SeoField::TITLE, SeoField::DESCRIPTION] as $role) {
            $field = $fields[$role];

            if ($field === null) {
                $rows[$role] = null;

                continue;
            }

            $range = $this->meta->range($field, $role, $fields['format']);
            $action = $this->meta->action($field, $context->newEntry, $provenance, $range) ?? MetaAction::Leave;
            $text = $meta->text($role);
            $current = $field->source === SeoSource::Custom && trim((string) $field->text) === '' ? null : $field->text;

            $row = [
                'role' => $role,
                'label' => $field->label,
                'text' => $text,
                'current' => $current,
                'inheritsFrom' => $field->inheritsFrom,
                'source' => $field->source->value,
                'action' => $action->value,
                'edited' => $meta->edited($role),
                'use' => $meta->uses($role),
                'dropped' => $meta->dropped[$role] ?? [],
                'limit' => $range->limit,
                'min' => $range->min,
                'max' => $range->max,
                'editable' => $action !== MetaAction::Leave || $text !== '',
            ];

            if ($role === SeoField::TITLE) {
                $own = $text !== '';
                $shown = $own ? $text : $pageTitle;
                $row += [
                    'own' => $own,
                    'pageTitle' => $pageTitle,
                    'length' => mb_strlen($shown),
                    'composed' => $fields['format']?->compose($shown) ?? $shown,
                    'note' => self::titleNote($own, $action, $range, $pageTitle, $meta, $field),
                ];
            } else {
                $row += [
                    'length' => mb_strlen($text !== '' ? $text : (string) $current),
                    'note' => self::descriptionNote($text, $action, $meta, $field),
                ];
            }

            $rows[$role] = $row;
        }

        $slug = $context->slug;
        $address = $slug === null ? null : [
            'slug' => $slug->settable ? ($meta->slug ?? $slug->current ?? '') : ($slug->current ?? ''),
            'base' => $slug->base,
            'editable' => $slug->settable,
            'edited' => $meta->edited('slug'),
            'note' => (new Message($slug->settable ? 'seo.search.address-new' : 'seo.search.address-kept'))->toArray(),
        ];

        return [
            'title' => $rows[SeoField::TITLE],
            'description' => $rows[SeoField::DESCRIPTION],
            'address' => $address,
            'fields' => $rows[SeoField::TITLE] !== null || $rows[SeoField::DESCRIPTION] !== null,
        ];
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}
     */
    private static function titleNote(bool $own, MetaAction $action, MetaRange $range, string $pageTitle, SearchMeta $meta, SeoField $field): array
    {
        $key = match (true) {
            $field->source === SeoSource::Disabled => 'seo.search.off',
            $own && $action === MetaAction::Suggest && ! $meta->uses(SeoField::TITLE) => 'seo.search.title-stays',
            $own && $meta->edited(SeoField::TITLE) => 'seo.search.edited',
            $own => 'seo.search.title-own',
            $action === MetaAction::Leave && $field->source === SeoSource::Template => 'seo.search.template',
            $range->pageTitleTooLong($pageTitle) => 'seo.search.title-long',
            default => 'seo.search.title-fits',
        };

        return (new Message($key, ['limit' => $range->limit]))->toArray();
    }

    /**
     * @return array{key: string, params: array<string, scalar|null>}
     */
    private static function descriptionNote(string $text, MetaAction $action, SearchMeta $meta, SeoField $field): array
    {
        $key = match (true) {
            $field->source === SeoSource::Disabled => 'seo.search.off',
            $action === MetaAction::Leave && $field->source === SeoSource::Template => 'seo.search.template',
            $text !== '' && $action === MetaAction::Suggest && ! $meta->uses(SeoField::DESCRIPTION) => 'seo.search.description-stays',
            $text !== '' && $meta->edited(SeoField::DESCRIPTION) => 'seo.search.edited',
            $text !== '' => 'seo.search.description-new',
            $action === MetaAction::Leave && $field->inherited() => 'seo.search.description-inherits',
            ($meta->dropped[SeoField::DESCRIPTION] ?? []) !== [] => 'seo.search.description-dropped',
            default => 'seo.search.description-empty',
        };

        return (new Message($key, ['field' => (string) $field->inheritsFrom]))->toArray();
    }
}
